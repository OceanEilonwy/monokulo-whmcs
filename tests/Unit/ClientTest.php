<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\ApiError;
use Monokulo\Whmcs\Client;
use Monokulo\Whmcs\Connection;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Trace;
use Monokulo\Whmcs\TransportError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The client against a stand-in Monokulo over real HTTP on this machine. */
final class ClientTest extends TestCase {
	private static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		self::$monokulo->reset();
	}

	private function connection(): Connection {
		return new Connection('shop.example', self::$monokulo->url . '/api/v1', 'pk_test', 'sk_test', 'whsec_test', 1);
	}

	public function test_finishing_connect_sends_the_token_webhook_and_name_and_resolves_the_entry(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_1']);
		$trace = new Trace();

		$credentials = (new Client($trace))->finishConnect(self::$monokulo->url . '/', 'tok', 'https://shop.example/modules/gateways/callback/monokulo.php');

		$this->assertSame(['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => self::$monokulo->url . '/api/v1', 'webhook_signing_secret' => 'whsec_1'], $credentials);
		$request = self::$monokulo->requests()[0];
		$this->assertSame(['token' => 'tok', 'webhook_url' => 'https://shop.example/modules/gateways/callback/monokulo.php', 'name' => 'WHMCS'], json_decode($request['body'], true));
		$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);
		$this->assertSame('application/json', $request['headers']['content-type']);
		$this->assertStringStartsWith('00-' . $trace->id() . '-', $request['headers']['traceparent']);
		$this->assertArrayNotHasKey('authorization', $request['headers']);
	}

	/** Without a webhook secret the shop could never trust a webhook, and without the entry it can't make orders: that's no connection. */
	public function test_finishing_without_every_credential_fails(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'api' => '/api/v1']);

		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertStringContainsString('missing webhook_signing_secret', $e->detail);
		}

		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_1', 'secret_token' => 'sk_1', 'webhook_signing_secret' => 'whsec_1']);
		$this->expectExceptionMessage('missing api');
		(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
	}

	public function test_a_monokulo_without_a_public_address_says_why(): void {
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 503, ['error' => 'no public address yet']);

		try {
			(new Client(new Trace()))->finishConnect(self::$monokulo->url, 'tok', 'https://shop.example/');
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame(503, $e->status);
			$this->assertSame('no public address yet', $e->detail);
		}
	}

	/** The entry with the secret key, then its create-order form; the payer goes to the order's mk:checkout link. */
	public function test_creating_an_order_follows_the_entry_form_with_the_secret_key(): void {
		Api::serve(self::$monokulo, 'order_1');

		$order = (new Client(new Trace()))->createOrder($this->connection(), ['amount' => '1.00', 'currency' => 'EUR']);

		$this->assertSame('order_1', $order->id);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $order->checkoutUrl);
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

	/** The form says where to post: the plugin doesn't build the address itself. */
	public function test_the_form_target_is_followed_wherever_it_points(): void {
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/elsewhere/orders';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);
		self::$monokulo->respond('POST', '/elsewhere/orders', 201, Api::order('order_2'));

		$this->assertSame('order_2', (new Client(new Trace()))->createOrder($this->connection(), [])->id);
		$this->assertSame('/elsewhere/orders', self::$monokulo->requests()[1]['path']);
	}

	public function test_an_entry_without_the_form_is_an_error(): void {
		$entry = Api::entry();
		unset($entry['_templates']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry);

		$this->expectExceptionMessage('no create-order form');

		(new Client(new Trace()))->createOrder($this->connection(), []);
	}

	public static function failures(): array {
		return [
			'rate limited'     => [429, Api::problem('rate-limited', 429, 'Too many requests', 'slow down'), 'slow down', 'rate-limited', 'error_busy', 'rate limited'],
			'bad key'          => [401, Api::problem('unauthorized', 401, 'No valid key', 'unknown key'), 'unknown key', 'unauthorized', 'error_unavailable', 'connect again from the Monokulo gateway settings'],
			'not allowed'      => [403, Api::problem('forbidden', 403, 'Not allowed with this key', 'no'), 'no', 'forbidden', 'error_unavailable', 'refused the store secret key'],
			'unknown currency' => [400, Api::problem('invalid-amount', 400, 'The order can\'t be priced', 'unknown currency: NOPE'), 'unknown currency: NOPE', 'invalid-amount', 'error_currency', 'can price the invoice\'s currency'],
			'key reused'       => [409, Api::problem('idempotency-conflict', 409, 'Key used for another order', 'used'), 'used', 'idempotency-conflict', 'error_generic', 'different amount'],
			'engine down'      => [503, Api::problem('engine-unavailable', 503, 'Payment engine unavailable', 'busy'), 'busy', 'engine-unavailable', 'error_busy', 'try again shortly'],
			'not a problem'    => [500, 'oops', 'oops', '', 'error_generic', ''],
			'wrong shape'      => [201, ['id' => 'x'], 'no order_id or mk:checkout link: {"id":"x"}', '', 'error_generic', ''],
			'not an object'    => [201, 'null', 'not JSON: null', '', 'error_generic', ''],
		];
	}

	#[DataProvider('failures')]
	public function test_a_failed_order_says_what_to_tell_the_customer_and_the_merchant(int $status, mixed $body, string $detail, string $problem, string $customer, string $hint): void {
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry());
		self::$monokulo->respond('POST', '/api/v1/orders', $status, $body, headers: $problem !== '' ? ['Content-Type' => Api::PROBLEM] : []);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
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

	/** A key Monokulo no longer knows is refused at the entry already. */
	public function test_a_refused_key_at_the_entry_is_a_problem(): void {
		self::$monokulo->respond('GET', '/api/v1', 401, Api::problem('unauthorized', 401, 'No valid key', 'unknown key'), headers: ['Content-Type' => Api::PROBLEM]);

		try {
			(new Client(new Trace()))->createOrder($this->connection(), []);
			$this->fail('no error');
		} catch (ApiError $e) {
			$this->assertSame([401, 'unauthorized'], [$e->status, $e->problem]);
			$this->assertCount(1, self::$monokulo->requests(), 'no order is attempted');
		}
	}

	public function test_nothing_listening_is_a_transport_error(): void {
		$connection = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);

		$this->expectException(TransportError::class);

		(new Client(new Trace()))->createOrder($connection, []);
	}

	public function test_errors_are_forwarded_to_the_entry_logs_with_the_secret_key(): void {
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');
		$entries = [['level' => 'error', 'message' => 'm', 'trace_id' => str_repeat('a', 32)]];

		(new Client(new Trace()))->forwardLogs($this->connection(), $entries);

		$request = self::$monokulo->requests()[0];
		$this->assertSame(['POST', '/api/v1/logs'], [$request['method'], $request['path']]);
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame(['entries' => $entries], json_decode($request['body'], true));
	}

	public function test_nothing_to_forward_sends_nothing_and_a_dead_monokulo_is_ignored(): void {
		(new Client(new Trace()))->forwardLogs($this->connection(), []);
		$this->assertSame([], self::$monokulo->requests());

		$dead = new Connection('shop.example', 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1', 'pk', 'sk', 'wh', 1);
		(new Client(new Trace()))->forwardLogs($dead, [['level' => 'error', 'message' => 'm', 'trace_id' => 'x']]);
		$this->addToAssertionCount(1);
	}
}
