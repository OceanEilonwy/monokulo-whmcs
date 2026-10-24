<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\ApiError;
use Monokulo\Whmcs\Client;
use Monokulo\Whmcs\Connection;
use Monokulo\Whmcs\Entry;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Tests\Support\MemoryEntryCache;
use Monokulo\Whmcs\Trace;
use Monokulo\Whmcs\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The client against a stand-in Monokulo over real HTTP on this machine. */
final class ClientTest extends TestCase {
	private const NOW = 1760000000;

	private static FakeMonokulo $monokulo;
	private MemoryEntryCache $cache;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
		$this->cache = new MemoryEntryCache();
	}

	private function connection(string $prefix = ''): Connection {
		return new Connection('shop.example', self::$monokulo->url . $prefix . '/api/v1', 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	private function client(int $now = self::NOW, ?Trace $trace = null): Client {
		return new Client($trace ?? new Trace(), $this->cache, $now);
	}

	/** @return list<string> "METHOD path" of every request the stand-in got */
	private static function calls(): array {
		return array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests());
	}

	public function test_finishing_connect_sends_the_token_webhook_and_name_and_resolves_the_entry(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_1']);
		$trace = new Trace();

		$credentials = $this->client(trace: $trace)->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/modules/gateways/callback/monokulo.php');

		$this->assertSame(['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => self::$monokulo->url . '/api/v1', 'onion_api' => null, 'webhook_signing_secret' => 'whsec_1'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/modules/gateways/callback/monokulo.php', 'name' => 'WHMCS'], json_decode($request['body'], true));
		$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** A Monokulo with an onion address hands over its entry there too. */
	public function test_finishing_connect_keeps_the_onion_entry(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1', 'onion_api' => Api::ONION . '/api/v1', 'webhook_signing_secret' => 'whsec_1']);

		$this->assertSame(Api::ONION . '/api/v1', $this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/')['onion_api']);
	}

	/** A Monokulo under a path: /finish is under it, and so is the entry it hands over. */
	public function test_finishing_connect_under_a_path_keeps_the_path(): void {
		self::$monokulo->respond('POST', '/monokulo/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/monokulo/api/v1', 'webhook_signing_secret' => 'whsec_1']);

		$credentials = $this->client()->finishConnect(self::$monokulo->url . '/monokulo/', 'tok', 'https://shop.example/');

		$this->assertSame(self::$monokulo->url . '/monokulo/api/v1', $credentials['api']);
		$this->assertSame(['POST /monokulo/plugins/connect/whmcs/finish'], self::calls());
	}

	/** Without a webhook secret the shop could never trust a webhook, and without the entry it can't make orders: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1']);

		try {
			$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertStringContainsString('missing webhook_signing_secret', $e->detail);
		}

		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'webhook_signing_secret' => 'whsec_1']);
		$this->expectExceptionMessage('missing api');
		$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	public static function finishProblems(): array {
		return [
			'no public address' => [Api::FINISH_PROBLEMS['no-public-address']],
			'token refused'     => [Api::FINISH_PROBLEMS['invalid-connect-code']],
			'rate limited'      => [Api::FINISH_PROBLEMS['rate-limited']],
			'bad request'       => [Api::FINISH_PROBLEMS['invalid-request']],
		];
	}

	/** The handshake's refusals are problems: their name, status and detail. */
	#[DataProvider('finishProblems')]
	public function test_a_refused_finish_is_a_problem(array $problem): void {
		Api::refuseFinish(self::$monokulo, $problem);

		try {
			$this->client()->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([$problem['status'], basename($problem['type']), $problem['detail']], [$e->status, $e->problem, $e->detail]);
		}
	}

	/** The entry with the secret key, then its create-order form; the order's links, resolved. */
	public function test_creating_an_order_follows_the_entry_form_with_the_secret_key(): void {
		Api::serve(self::$monokulo, 'order_1');

		$order = $this->client()->createOrder($this->connection(), ['amount' => '1.00', 'currency' => 'EUR']);

		$this->assertSame('order_1', $order->id);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $order->checkoutUrl);
		$this->assertNull($order->onionCheckoutUrl, 'no onion address');
		[$entry, $create] = self::$monokulo->requests();
		$this->assertSame(['GET', '/api/v1'], [$entry['method'], $entry['path']]);
		$this->assertSame(['POST', '/api/v1/orders'], [$create['method'], $create['path']]);
		foreach ([$entry, $create] as $request) {
			$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
			$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);
			$this->assertStringContainsString(Api::HAL_FORMS, $request['headers']['accept']);
		}
		$this->assertSame('application/json', $create['headers']['content-type']);
		$this->assertSame(['amount' => '1.00', 'currency' => 'EUR'], json_decode($create['body'], true));
	}

	/** With an onion address, the order's checkout there, absolute. */
	public function test_an_order_has_its_onion_checkout_when_monokulo_has_an_onion_address(): void {
		Api::serve(self::$monokulo, 'order_1', onion: true);

		$order = $this->client()->createOrder($this->connection(), []);

		$this->assertSame(Api::ONION . '/checkout/order_1', $order->onionCheckoutUrl);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $order->checkoutUrl);
	}

	/** Whether Monokulo has an onion address, from the entry, kept like the rest of it. */
	public function test_the_entry_says_where_the_onion_api_is(): void {
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(onion: true), headers: Api::entryHeaders());
		$this->assertSame(Api::ONION . '/api/v1', $this->client()->onionApi($this->connection()));
		$this->assertSame(Api::ONION . '/api/v1', $this->client()->onionApi($this->connection()));
		$this->assertSame(['GET /api/v1'], self::calls());

		$this->cache->entries = [];
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());
		$this->assertNull($this->client()->onionApi($this->connection()));
	}

	/** A kept entry without an onion address is asked about again before it is believed: unchanged, a 304. */
	public function test_a_kept_entry_without_an_onion_address_is_revalidated(): void {
		$this->cache->entries['sk_test'] = new Entry(self::$monokulo->url . '/api/v1', Api::entry(), Api::ETAG, self::NOW + 1000);
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());

		$this->assertNull($this->client()->onionApi($this->connection()));

		$this->assertSame(['GET /api/v1'], self::calls());
		$this->assertSame(Api::ETAG, self::$monokulo->requests()[0]['headers']['if-none-match']);
		$this->assertSame(self::NOW + 3600, $this->cache->entries['sk_test']->expiresAt);
	}

	/** Under a path: every address is the link Monokulo gave, path and all. */
	public function test_under_a_path_every_link_keeps_it(): void {
		Api::serve(self::$monokulo, 'order_1', prefix: '/monokulo');

		$order = $this->client()->createOrder($this->connection('/monokulo'), []);

		$this->assertSame(self::$monokulo->url . '/monokulo/checkout/order_1', $order->checkoutUrl);
		$this->assertSame(['GET /monokulo/api/v1', 'POST /monokulo/api/v1/orders'], self::calls());
	}

	/** The form says where to post: the plugin doesn't build the address itself. */
	public function test_the_form_target_is_followed_wherever_it_points(): void {
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/elsewhere/orders';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		self::$monokulo->respond('POST', '/elsewhere/orders', 201, Api::order('order_2'));

		$this->assertSame('order_2', $this->client()->createOrder($this->connection(), [])->id);
		$this->assertSame('/elsewhere/orders', self::$monokulo->requests()[1]['path']);
	}

	public function test_an_entry_without_the_form_is_an_error(): void {
		$entry = Api::entry();
		unset($entry['_templates']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);

		$this->expectExceptionMessage('no create-order form');

		$this->client()->createOrder($this->connection(), []);
	}

	/** The entry is kept for its max-age: within it, an order is one request. */
	public function test_the_entry_is_kept_so_an_order_is_one_request(): void {
		Api::serve(self::$monokulo);

		$this->client()->createOrder($this->connection(), []);
		$this->client(self::NOW + 3599)->createOrder($this->connection(), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders', 'POST /api/v1/orders'], self::calls());
		$kept = $this->cache->entries['sk_test'];
		$this->assertSame([Api::ETAG, self::NOW + 3600], [$kept->etag, $kept->expiresAt]);
	}

	/** Expired, it is asked for again with its ETag: unchanged, 304, and kept for another max-age. */
	public function test_an_expired_entry_is_revalidated_with_its_etag(): void {
		Api::serve(self::$monokulo);
		$this->client()->createOrder($this->connection(), []);
		self::$monokulo->reset();
		Api::serve(self::$monokulo);

		$this->client(self::NOW + 3600)->createOrder($this->connection(), []);

		[$entry, $create] = self::$monokulo->requests();
		$this->assertSame(['GET', '/api/v1', Api::ETAG], [$entry['method'], $entry['path'], $entry['headers']['if-none-match']]);
		$this->assertSame('/api/v1/orders', $create['path']);
		$this->assertSame(self::NOW + 7200, $this->cache->entries['sk_test']->expiresAt);
		$this->assertSame('POST', $this->cache->entries['sk_test']->form('create-order')['method'], 'the copy is the one kept');
	}

	/** A changed entry comes back whole, and its form is the one followed. */
	public function test_a_changed_entry_replaces_the_kept_one(): void {
		$this->cache->entries['sk_test'] = new Entry(self::$monokulo->url . '/api/v1', Api::entry(), '"old"', self::NOW - 1);
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/api/v1/new-orders';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry, headers: Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/new-orders', 201, Api::order());

		$this->client()->createOrder($this->connection(), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/new-orders'], self::calls());
		$this->assertSame('"old"', self::$monokulo->requests()[0]['headers']['if-none-match']);
		$this->assertSame(Api::ETAG, $this->cache->entries['sk_test']->etag);
	}

	public static function staleAnswers(): array {
		return [
			'401' => [401, Api::ORDER_PROBLEMS['unauthorized']],
			'404' => [404, Api::problem('not-found', 404, 'Not found', 'no such route')],
			'405' => [405, Api::problem('method-not-allowed', 405, 'Method not allowed', 'not here')],
		];
	}

	/** A kept form refused with 401, 404 or 405: the entry is read again at once, and its form followed. */
	#[DataProvider('staleAnswers')]
	public function test_a_refused_kept_form_reads_the_entry_again(int $status, array $problem): void {
		$this->cache->entries['sk_test'] = new Entry(self::$monokulo->url . '/api/v1', ['_templates' => ['create-order' => ['method' => 'POST', 'target' => '/api/v0/orders']]], Api::ETAG, self::NOW + 100);
		self::$monokulo->respond('POST', '/api/v0/orders', $status, $problem, headers: ['Content-Type' => Api::PROBLEM]);
		Api::serve(self::$monokulo, 'order_9');

		$this->assertSame('order_9', $this->client()->createOrder($this->connection(), [])->id);

		$this->assertSame(['POST /api/v0/orders', 'GET /api/v1', 'POST /api/v1/orders'], self::calls());
		$this->assertArrayNotHasKey('if-none-match', self::$monokulo->requests()[1]['headers'], 'read whole, not revalidated');
		$this->assertSame('/api/v1/orders', parse_url($this->cache->entries['sk_test']->form('create-order')['target'], PHP_URL_PATH));
	}

	/** A form from an entry read just now that is refused is refused: no loop. */
	public function test_a_refused_fresh_form_is_not_retried(): void {
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', 404, Api::problem('not-found', 404, 'Not found', 'gone'), headers: ['Content-Type' => Api::PROBLEM]);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([404, 'not-found'], [$e->status, $e->problem]);
		}
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], self::calls());
	}

	/** A kept entry without the form (the key lost it): read again before giving up. */
	public function test_a_kept_entry_without_the_form_is_read_again(): void {
		$this->cache->entries['sk_test'] = new Entry(self::$monokulo->url . '/api/v1', ['_links' => []], Api::ETAG, self::NOW + 100);
		Api::serve(self::$monokulo);

		$this->client()->createOrder($this->connection(), []);

		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], self::calls());
	}

	public static function failures(): array {
		return [
			'rate limited'     => [429, Api::ORDER_PROBLEMS['rate-limited'], 'Rate limit exceeded; retry in 120 s.', 'rate-limited', 'error_busy', 'rate limited'],
			'bad key'          => [401, Api::ORDER_PROBLEMS['unauthorized'], 'This key was not accepted. Check it, or connect the store or app again.', 'unauthorized', 'error_unavailable', 'connect again from the Monokulo gateway settings'],
			'not allowed'      => [403, Api::ORDER_PROBLEMS['forbidden'], 'This store only accepts orders from its verified websites, or from its own server using the store\'s secret key.', 'forbidden', 'error_unavailable', 'refused the store secret key'],
			'unknown currency' => [400, Api::ORDER_PROBLEMS['invalid-amount'], 'unknown currency: NOPE', 'invalid-amount', 'error_currency', 'can price the invoice\'s currency'],
			'key reused'       => [409, Api::ORDER_PROBLEMS['idempotency-conflict'], 'this idempotency_key was already used for a different order', 'idempotency-conflict', 'error_generic', 'different amount'],
			'engine down'      => [503, Api::ORDER_PROBLEMS['engine-unavailable'], 'The payment engine is busy or unreachable. Try again in a moment.', 'engine-unavailable', 'error_busy', 'payment engine is busy; no order was made'],
			'no rate'          => [503, Api::ORDER_PROBLEMS['rates-unavailable'], 'There\'s no exchange rate for USD right now: the rate providers are busy or unreachable. Try again in 42 s.', 'rates-unavailable', 'error_rates', 'no exchange rate for the invoice\'s currency right now'],
			'not a problem'    => [500, 'oops', 'oops', '', 'error_generic', ''],
			'wrong shape'      => [201, ['id' => 'x'], 'no order_id or mk:checkout link: {"id":"x"}', '', 'error_generic', ''],
			'not an object'    => [201, 'null', 'not JSON: null', '', 'error_generic', ''],
		];
	}

	#[DataProvider('failures')]
	public function test_a_failed_order_says_what_to_tell_the_customer_and_the_merchant(int $status, mixed $body, string $detail, string $problem, string $customer, string $hint): void {
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', $status, $body, headers: $problem !== '' ? ['Content-Type' => Api::PROBLEM] : []);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($status, $e->status);
			$this->assertSame($detail, $e->detail);
			$this->assertSame($problem, $e->problem);
			$this->assertSame($customer, $e->customerMessageKey());
			if ($hint === '') {
				$this->assertSame('', $e->merchantHint());
			} else {
				$this->assertStringContainsString($hint, $e->merchantHint());
				$this->assertStringContainsString('https://docs.monokulo.com/problems/' . $problem, $e->merchantHint(), 'the problem\'s page');
			}
		}
	}

	/** A key Monokulo doesn't know is refused at the entry already, and nothing is kept. */
	public function test_a_refused_key_at_the_entry_is_a_problem(): void {
		self::$monokulo->respond('GET', '/api/v1', 401, Api::ORDER_PROBLEMS['unauthorized'], headers: ['Content-Type' => Api::PROBLEM]);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([401, 'unauthorized'], [$e->status, $e->problem]);
			$this->assertSame(['GET /api/v1'], self::calls(), 'no order is attempted');
			$this->assertSame([], $this->cache->entries);
		}
	}

	public function test_nothing_listening_is_a_transport_error(): void {
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		$this->client()->createOrder($connection, []);
	}

	public function test_errors_are_sent_with_the_entry_send_logs_form(): void {
		Api::serve(self::$monokulo, prefix: '/monokulo');
		self::$monokulo->respond('POST', '/monokulo/api/v1/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		$this->client()->forwardLogs($this->connection('/monokulo'), $entries);

		$this->assertSame(['GET /monokulo/api/v1', 'POST /monokulo/api/v1/logs'], self::calls());
		$request = self::$monokulo->requests()[1];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	/** A key the entry doesn't offer send-logs sends nothing. */
	public function test_without_the_send_logs_form_nothing_is_sent(): void {
		$entry = Api::entry();
		unset($entry['_templates']['send-logs']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry, headers: Api::entryHeaders());

		$this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame(['GET /api/v1'], self::calls());
	}

	public function test_nothing_to_forward_sends_nothing_a_dead_monokulo_is_ignored_and_a_refusal_returned(): void {
		$this->assertNull($this->client()->forwardLogs($this->connection(), []));
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		$this->assertNull($this->client()->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]));

		self::$monokulo->respond('GET', '/api/v1', 401, Api::ENTRY_PROBLEMS['unauthorized'], headers: ['Content-Type' => Api::PROBLEM]);
		$refused = $this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->assertSame(['GET /api/v1'], self::calls());
		$this->assertSame([401, 'unauthorized'], [$refused?->status, $refused?->problem]);
	}

	/** Client logs off for the store: the refusal comes back, with its problem and what to change. */
	public function test_logs_off_is_returned_with_what_to_change(): void {
		Api::serve(self::$monokulo);
		$problem = Api::LOGS_PROBLEMS['logs-off'];
		self::$monokulo->respond('POST', '/api/v1/logs', 403, $problem, headers: ['Content-Type' => Api::PROBLEM]);

		$refused = $this->client()->forwardLogs($this->connection(), [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);

		$this->assertSame([403, 'logs-off', $problem['detail']], [$refused?->status, $refused?->problem, $refused?->detail]);
		$this->assertStringContainsString('turn them on in the store\'s settings on Monokulo', $refused->merchantHint());
	}

	public static function cacheControls(): array {
		return [
			'the entry\'s'   => ['private, max-age=3600', 3600],
			'spaced, quoted' => ['max-age = "60" , private', 60],
			'no-store'       => ['no-store', 0],
			'no-cache'       => ['private, no-cache, max-age=60', 0],
			'nothing'        => ['', 0],
		];
	}

	#[DataProvider('cacheControls')]
	public function test_how_long_an_answer_may_be_kept(string $header, int $seconds): void {
		$this->assertSame($seconds, Entry::maxAge($header));
	}

	public static function retryAfters(): array {
		return [
			'in the problem'    => [['retry_after' => 30], [], 30],
			'in the header'     => [[], ['Retry-After' => '45'], 45],
			'the problem wins'  => [['retry_after' => 30], ['Retry-After' => '45'], 30],
			'a date, not used'  => [[], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null],
			'neither'           => [[], [], null],
		];
	}

	/** How long Monokulo asks to wait before trying again: the problem's retry_after, else Retry-After in seconds. */
	#[DataProvider('retryAfters')]
	public function test_a_problem_says_how_long_to_wait(array $extra, array $headers, ?int $seconds): void {
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', 503, array_diff_key(Api::ORDER_PROBLEMS['rates-unavailable'], ['retry_after' => true]) + $extra, headers: ['Content-Type' => Api::PROBLEM] + $headers);

		try {
			$this->client()->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame($seconds, $e->retryAfter);
			$this->assertTrue($e->tryAgain());
		}
	}
}
