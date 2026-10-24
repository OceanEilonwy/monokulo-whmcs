<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Links;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\Calls;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use Monokulo\Whmcs\Tests\Support\WhmcsTestCase;
use WHMCS\Database\Capsule;

/**
 * Pay with Monero: the invoice's button, through the callback, against
 * WHMCS's invoices and a stand-in Monokulo.
 */
final class PayTest extends WhmcsTestCase {
	use Calls;

	protected function setUp(): void {
		parent::setUp();
		Whmcs::connect(self::$monokulo->url, 'whsec');
	}

	/** @return array<string, string> the query of the invoice's Pay with Monero link */
	private function payLink(int $invoice): array {
		$repository = new Repository(Capsule::connection());

		return self::query(Links::pay(Whmcs::URL, $repository->linkKey(), $invoice));
	}

	private function monokuloCreates(string $orderId = 'order_1', ?array $problem = null, string $prefix = '', bool $onion = false): void {
		Api::serve(self::$monokulo, $orderId, $problem, $prefix, $onion);
	}

	/** @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}> the orders WHMCS asked Monokulo for */
	private function creates(): array {
		return array_values(array_filter(self::$monokulo->requests(), static fn(array $r): bool => $r['method'] === 'POST' && str_ends_with($r['path'], '/orders')));
	}

	/** @return array<string, mixed> */
	private function sent(int $i = 0): array {
		return json_decode($this->creates()[$i]['body'], true);
	}

	public function test_the_client_is_sent_to_pay_the_invoice_balance(): void {
		$invoice = Whmcs::invoice(['Starter - example.com' => '80.00', 'Domain renewal' => '15.00', 'SSL' => '5.50'], 'EUR');
		$this->monokuloCreates();

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(303, $response->status);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $response->headers['Location']);
		$this->assertSame([
			'amount'                 => '100.5',
			'currency'               => 'EUR',
			'merchant_order_id'      => (string)$invoice,
			'show_merchant_order_id' => true,
			'description'            => 'Invoice #' . $invoice . ': Starter - example.com + 2 more',
			'idempotency_key'        => 'whmcs:' . $invoice . ':100.5:EUR:0',
		], $this->sent());
		$request = $this->creates()[0];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);

		$this->assertSame([['monokulo_order_id' => 'order_1', 'invoice_id' => $invoice, 'amount' => '100.5', 'currency' => 'EUR', 'attempt' => 0, 'expired' => 0]], array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['monokulo_order_id', 'invoice_id', 'amount', 'currency', 'attempt', 'expired'])), Whmcs::rows('mod_monokulo_order')));
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($invoice), 'nothing paid yet');
		$log = Whmcs::gatewayLog();
		$this->assertCount(1, $log);
		$this->assertSame('Sent to pay', $log[0]['result']);
		$this->assertStringContainsString('Client sent to pay with Monero: ' . self::$monokulo->url . '/checkout/order_1', $log[0]['data']);
	}

	/**
	 * Monokulo leaves out a field with no value rather than sending `null`:
	 * an order body without its amount, currency, merchant order id,
	 * description or cancelled_at is still the order, and what WHMCS keeps
	 * is what it asked for.
	 */
	public function test_an_order_without_its_optional_fields_is_still_the_order(): void {
		$invoice = Whmcs::invoice(['Starter - example.com' => '25.00'], 'USD');
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(), headers: Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', 201, Api::bareOrder(), headers: ['Content-Type' => Api::HAL_FORMS, 'Location' => '/api/v1/orders/order_1']);

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(303, $response->status);
		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $response->headers['Location']);
		$this->assertSame([['monokulo_order_id' => 'order_1', 'amount' => '25', 'currency' => 'USD']], array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['monokulo_order_id', 'amount', 'currency'])), Whmcs::rows('mod_monokulo_order')));
		$this->assertStringContainsString('25 USD', Whmcs::gatewayLog()[0]['data']);
	}

	/** Part paid already (by credit, say): only the balance is asked for. */
	public function test_only_the_balance_is_asked_for(): void {
		$invoice = Whmcs::invoice(['Hosting' => '100.00']);
		addInvoicePayment($invoice, 'credit-1', '30', 0, 'credit');
		$this->monokuloCreates();

		$this->get($this->payLink($invoice));

		$this->assertSame('70', $this->sent()['amount']);
		$this->assertSame('USD', $this->sent()['currency']);
	}

	public function test_a_custom_invoice_number_is_what_the_client_sees(): void {
		$invoice = Whmcs::invoice(invoiceNumber: 'INV-2026-007');
		$this->monokuloCreates();

		$this->get($this->payLink($invoice));

		$this->assertSame('INV-2026-007', $this->sent()['merchant_order_id']);
		$this->assertStringStartsWith('Invoice #INV-2026-007: ', $this->sent()['description']);
	}

	/** Pressed again: the same key, so Monokulo answers with the same order; WHMCS keeps it once. */
	public function test_pressing_again_returns_to_the_same_order(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates();

		$this->get($this->payLink($invoice));
		$again = $this->get($this->payLink($invoice));

		$this->assertSame(303, $again->status);
		$this->assertSame($this->sent(0)['idempotency_key'], $this->sent(1)['idempotency_key']);
		$this->assertCount(1, Whmcs::rows('mod_monokulo_order'));
		$this->assertCount(1, Whmcs::gatewayLog());
	}

	/** After the first order expired, paying again is a new attempt: a new key, a new order. */
	public function test_after_an_order_expired_paying_again_starts_a_new_one(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates('order_1');
		$this->get($this->payLink($invoice));
		(new Repository(Capsule::connection()))->markExpired('order_1');
		$this->monokuloCreates('order_2');

		$this->assertSame(self::$monokulo->url . '/checkout/order_2', $this->get($this->payLink($invoice))->headers['Location']);

		$this->assertSame('whmcs:' . $invoice . ':100:USD:1', $this->sent(1)['idempotency_key']);
		$this->assertSame([0, 1], array_column(Whmcs::rows('mod_monokulo_order'), 'attempt'));
	}

	public static function badLinks(): array {
		return [
			'no signature'      => [static fn(int $id, array $q): array => ['action' => 'pay', 'invoice' => (string)$id]],
			'another invoice'   => [static fn(int $id, array $q): array => ['invoice' => (string)($id + 1)] + $q],
			'a made-up one'     => [static fn(int $id, array $q): array => ['sig' => str_repeat('a', 64)] + $q],
			'not a number'      => [static fn(int $id, array $q): array => ['invoice' => 'x'] + $q],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('badLinks')]
	public function test_a_link_this_whmcs_did_not_make_is_refused(\Closure $tamper): void {
		$invoice = Whmcs::invoice();

		$response = $this->get($tamper($invoice, $this->payLink($invoice)));

		$this->assertSame(403, $response->status);
		$this->assertStringContainsString('This payment link is not valid', $response->body);
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_a_paid_invoice_has_nothing_to_pay(): void {
		$invoice = Whmcs::invoice();
		addInvoicePayment($invoice, 'bank-1', '100', 0, 'banktransfer');

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(409, $response->status);
		$this->assertStringContainsString('This invoice has nothing left to pay.', $response->body);
		$this->assertStringContainsString('href="' . Whmcs::URL . 'viewinvoice.php?id=' . $invoice . '"', $response->body);
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_a_cancelled_invoice_has_nothing_to_pay(): void {
		$invoice = Whmcs::invoice();
		Capsule::table('tblinvoices')->where('id', $invoice)->update(['status' => 'Cancelled']);

		$this->assertSame(409, $this->get($this->payLink($invoice))->status);
	}

	public function test_a_deleted_invoice_is_not_found(): void {
		$invoice = Whmcs::invoice();
		Capsule::table('tblinvoices')->where('id', $invoice)->delete();

		$this->assertSame(404, $this->get($this->payLink($invoice))->status);
	}

	/** Disconnected since the invoice was shown: the client is told, the merchant warned. */
	public function test_not_connected_tells_the_client_and_warns_the_merchant(): void {
		$invoice = Whmcs::invoice();
		Capsule::table('mod_monokulo_connection')->delete();

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(503, $response->status);
		$this->assertStringContainsString('Monero payments are not set up yet', $response->body);
		$this->assertStringContainsString('not connected to Monokulo', Whmcs::logs());
		$this->assertSame([], self::$monokulo->requests());
	}

	public static function monokuloFailures(): array {
		return [
			'rate limited'     => [Api::ORDER_PROBLEMS['rate-limited'], 'Monero payments are busy right now', 'rate limited'],
			'key refused'      => [Api::ORDER_PROBLEMS['unauthorized'], 'Monero payments are not available right now', 'connect again from the Monokulo gateway settings'],
			'unknown currency' => [Api::ORDER_PROBLEMS['invalid-amount'], 'currency can&#039;t be paid in Monero', 'unknown currency: NOPE'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('monokuloFailures')]
	public function test_when_monokulo_refuses_the_client_is_told_and_the_merchant_given_a_hint(array $problem, string $client, string $hint): void {
		$status = $problem['status'];
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(problem: $problem);

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(502, $response->status);
		$this->assertStringContainsString($client, $response->body);
		$this->assertStringContainsString('Back to the invoice', $response->body);
		$this->assertStringContainsString('Creating the Monokulo order for invoice ' . $invoice . ' failed: HTTP ' . $status, Whmcs::logs());
		$this->assertStringContainsString($hint, Whmcs::logs());
		$this->assertSame([], Whmcs::rows('mod_monokulo_order'));
	}

	public static function tryAgainShortly(): array {
		return [
			'no exchange rate' => [Api::ORDER_PROBLEMS['rates-unavailable'], ['Retry-After' => '42'], 'Monero prices are not available right now', 'no exchange rate for the invoice\'s currency right now', '42'],
			'engine busy'      => [Api::ORDER_PROBLEMS['engine-unavailable'], [], 'Monero payments are busy right now', 'payment engine is busy', null],
		];
	}

	/**
	 * No exchange rate right now, or the engine busy: no order was made. The
	 * client is asked to try again shortly (with Monokulo's Retry-After),
	 * the merchant told why, and pressing Pay with Monero again sends the
	 * same request with the same idempotency key, which then makes the order.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('tryAgainShortly')]
	public function test_a_try_again_problem_asks_the_client_to_try_again_with_the_same_key(array $problem, array $headers, string $client, string $hint, ?string $retryAfter): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates();
		self::$monokulo->respond('POST', '/api/v1/orders', 503, $problem, headers: ['Content-Type' => Api::PROBLEM] + $headers);

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(503, $response->status);
		$this->assertSame($retryAfter, $response->headers['Retry-After'] ?? null);
		$this->assertStringContainsString($client, $response->body);
		$this->assertStringContainsString('try again', $response->body);
		$this->assertStringContainsString('Back to the invoice', $response->body);
		$this->assertStringContainsString('Creating the Monokulo order for invoice ' . $invoice . ' failed: HTTP 503: ' . $problem['detail'], Whmcs::logs());
		$this->assertStringContainsString($hint, Whmcs::logs());
		$this->assertStringContainsString('no order was made', Whmcs::logs());
		$this->assertStringContainsString($problem['type'], Whmcs::logs());
		if ($retryAfter !== null) {
			$this->assertStringContainsString('waiting ' . $retryAfter . ' seconds', Whmcs::logs());
		}
		$this->assertSame([], Whmcs::rows('mod_monokulo_order'));

		// Pressed again, once Monokulo can: the same request, and the order.
		$this->monokuloCreates();
		$again = $this->get($this->payLink($invoice));

		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $again->headers['Location']);
		$this->assertSame($this->sent(0), $this->sent(1));
		$this->assertCount(1, Whmcs::rows('mod_monokulo_order'));
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		Capsule::table('mod_monokulo_connection')->update(['api' => 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1']);
		$invoice = Whmcs::invoice();

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(502, $response->status);
		$this->assertStringContainsString('could not be reached', $response->body);
		$this->assertStringContainsString('could not reach', Whmcs::logs());
	}

	/** As a shop connected on 0.1.0 has it: no kept entry, no onion entry. */
	private static function connectedOnTheLastVersion(): void {
		$schema = Capsule::schema();
		$schema->drop('mod_monokulo_connection');
		$schema->create('mod_monokulo_connection', static function (\Illuminate\Database\Schema\Blueprint $t): void {
			$t->string('host', 191)->primary();
			$t->string('api', 255)->nullable();
			$t->string('public_key', 128);
			$t->string('secret_token', 255);
			$t->string('webhook_secret', 255);
			$t->integer('connected_at');
		});
		Capsule::table('mod_monokulo_connection')->insert([
			'host'           => Whmcs::HOST,
			'api'            => self::$monokulo->url . '/api/v1',
			'public_key'     => 'pk_test',
			'secret_token'   => 'sk_test',
			'webhook_secret' => 'whsec',
			'connected_at'   => 1760000000,
		]);
	}

	/**
	 * Upgraded from 0.1.0 without connecting again: the same key and entry,
	 * the new columns added, the entry kept from then on, and Monokulo's
	 * onion entry picked up from it.
	 */
	public function test_a_shop_connected_on_the_last_version_keeps_working(): void {
		self::connectedOnTheLastVersion();
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(onion: true);

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $response->headers['Location']);
		$this->assertSame('Bearer sk_test', $this->creates()[0]['headers']['authorization']);
		$row = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertSame([Api::ONION . '/api/v1', Api::ETAG], [$row['onion_api'], $row['entry_etag']]);
	}

	/** The entry is kept: the next client's order is one request. */
	public function test_the_entry_is_kept_between_clients(): void {
		$this->monokuloCreates('order_1');
		$this->get($this->payLink(Whmcs::invoice()));
		$this->monokuloCreates('order_2');

		$this->get($this->payLink(Whmcs::invoice()));

		$this->assertSame(['/api/v1', '/api/v1/orders', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	/** The key was replaced in Monokulo since the entry was kept: read again, and the new key's form followed. */
	public function test_a_kept_entry_refused_is_read_again(): void {
		$this->monokuloCreates('order_1');
		$this->get($this->payLink(Whmcs::invoice()));
		$entry = Api::entry();
		$entry['_templates']['create-order']['target'] = '/api/v1/orders-moved';
		self::$monokulo->respond('GET', '/api/v1', 200, $entry, headers: ['ETag' => '"new"'] + Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', 405, Api::problem('method-not-allowed', 405, 'Method not allowed', 'moved'), headers: ['Content-Type' => Api::PROBLEM]);
		self::$monokulo->respond('POST', '/api/v1/orders-moved', 201, Api::order('order_2'));

		$response = $this->get($this->payLink(Whmcs::invoice()));

		$this->assertSame(self::$monokulo->url . '/checkout/order_2', $response->headers['Location']);
		$this->assertSame(['/api/v1', '/api/v1/orders', '/api/v1/orders', '/api/v1', '/api/v1/orders-moved'], array_column(self::$monokulo->requests(), 'path'));
		$this->assertSame('"new"', Whmcs::rows('mod_monokulo_connection')[0]['entry_etag']);
	}

	/** A Monokulo under a path: the entry, the form and the checkout all keep it. */
	public function test_a_monokulo_under_a_path(): void {
		Capsule::table('mod_monokulo_connection')->update(['api' => self::$monokulo->url . '/monokulo/api/v1']);
		$this->monokuloCreates(prefix: '/monokulo');

		$response = $this->get($this->payLink(Whmcs::invoice()));

		$this->assertSame(self::$monokulo->url . '/monokulo/checkout/order_1', $response->headers['Location']);
		$this->assertSame(['/monokulo/api/v1', '/monokulo/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	private const ONION_SHOP = 'http://shopabcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuv.onion';

	/** A client on the shop's onion address: sent to the order's checkout on Monokulo's onion address. */
	public function test_a_client_on_the_shops_onion_address_pays_over_tor(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(onion: true);

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink($invoice), origin: self::ONION_SHOP));

		$this->assertSame(303, $response->status);
		$this->assertSame(Api::ONION . '/checkout/order_1', $response->headers['Location']);
		$this->assertStringContainsString('Client sent to pay with Monero: ' . Api::ONION . '/checkout/order_1', Whmcs::gatewayLog()[0]['data']);
		$this->assertStringContainsString('over Tor', Whmcs::logs());
		$this->assertSame(['GET /api/v1', 'POST /api/v1/orders'], array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests()), 'the entry read once, for both');
	}

	/** A client on the shop's web address goes to the web checkout, even when there's an onion one. */
	public function test_a_client_on_the_web_address_pays_on_the_web(): void {
		$this->monokuloCreates(onion: true);

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink(Whmcs::invoice()), origin: 'http://localhost:8091'));

		$this->assertSame(self::$monokulo->url . '/checkout/order_1', $response->headers['Location']);
	}

	/** Monokulo has no onion address: the Tor client is told, never sent to the web, and the merchant warned. */
	public function test_a_client_on_tor_is_not_sent_to_the_web_when_monokulo_has_no_onion_address(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates();

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink($invoice), origin: self::ONION_SHOP));

		$this->assertSame(503, $response->status);
		$this->assertArrayNotHasKey('Location', $response->headers);
		$this->assertStringContainsString('can&#039;t be made over Tor here yet', $response->body);
		$this->assertStringContainsString('href="' . self::ONION_SHOP . '/viewinvoice.php?id=' . $invoice . '"', $response->body, 'back to the invoice on the onion address');
		$this->assertStringNotContainsString(self::$monokulo->url, $response->body);
		$this->assertStringContainsString('your Monokulo has no onion address', Whmcs::logs());
		$this->assertSame([], Whmcs::gatewayLog(), 'not sent to pay');
		$this->assertSame(['GET /api/v1'], array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests()), 'the entry said so: no order was made');
		$this->assertSame([], Whmcs::rows('mod_monokulo_order'));
	}

	/** A kept entry without an onion address is revalidated before a Tor client is refused: unchanged (304), refused, no order. */
	public function test_a_second_tor_client_is_refused_after_revalidating(): void {
		$this->monokuloCreates();
		$this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink(Whmcs::invoice()), origin: self::ONION_SHOP));
		self::$monokulo->reset();
		$this->monokuloCreates();

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink(Whmcs::invoice()), origin: self::ONION_SHOP));

		$this->assertSame(503, $response->status);
		$this->assertSame(['GET /api/v1'], array_map(static fn(array $r): string => $r['method'] . ' ' . $r['path'], self::$monokulo->requests()));
		$this->assertSame(Api::ETAG, self::$monokulo->requests()[0]['headers']['if-none-match']);
		$this->assertSame([], $this->creates());
	}

	/** Monokulo has just gained an onion address: the kept entry without it is revalidated, and the Tor client pays over Tor. */
	public function test_a_monokulo_that_just_gained_an_onion_address_takes_tor_clients_at_once(): void {
		$this->monokuloCreates();
		$this->get($this->payLink(Whmcs::invoice()));
		self::$monokulo->reset();
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(onion: true), headers: ['ETag' => '"onion"'] + Api::entryHeaders());
		self::$monokulo->respond('POST', '/api/v1/orders', 201, Api::order('order_2', onion: true));

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink(Whmcs::invoice()), origin: self::ONION_SHOP));

		$this->assertSame(Api::ONION . '/checkout/order_2', $response->headers['Location']);
		$this->assertSame(Api::ETAG, self::$monokulo->requests()[0]['headers']['if-none-match']);
		$row = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertSame(['"onion"', Api::ONION . '/api/v1'], [$row['entry_etag'], $row['onion_api']]);
	}

	/** The entry has an onion address but the order no onion checkout: still never sent to the web. */
	public function test_an_order_without_an_onion_checkout_is_not_paid_on_the_web(): void {
		$this->monokuloCreates();
		self::$monokulo->respond('GET', '/api/v1', 200, Api::entry(onion: true), headers: Api::entryHeaders());

		$response = $this->call(new \Monokulo\Whmcs\Request('GET', $this->payLink(Whmcs::invoice()), origin: self::ONION_SHOP));

		$this->assertSame(503, $response->status);
		$this->assertArrayNotHasKey('Location', $response->headers);
		$this->assertCount(1, $this->creates());
	}

	/** With "Send errors to Monokulo" on, the merchant's warning goes to Monokulo too, with its trace. */
	public function test_errors_are_forwarded_when_the_merchant_asked(): void {
		Whmcs::setting('forwardErrors', 'on');
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(problem: Api::ORDER_PROBLEMS['rate-limited']);
		self::$monokulo->respond('POST', '/api/v1/logs', 204, '');

		$this->get($this->payLink($invoice));

		$requests = self::$monokulo->requests();
		$this->assertSame('/api/v1/logs', $requests[2]['path']);
		$entries = json_decode($requests[2]['body'], true)['entries'];
		$this->assertSame('error', $entries[0]['level']);
		$this->assertStringContainsString('HTTP 429', $entries[0]['message']);
		$this->assertStringContainsString('[trace ' . $entries[0]['trace_id'] . ']', Whmcs::logs());
		$this->assertStringContainsString('00-' . $entries[0]['trace_id'] . '-', $requests[1]['headers']['traceparent'], 'the order request carried the same trace');
	}

	public function test_errors_stay_in_whmcs_otherwise(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(problem: Api::ORDER_PROBLEMS['rate-limited']);

		$this->get($this->payLink($invoice));

		$this->assertSame(['/api/v1', '/api/v1/orders'], array_column(self::$monokulo->requests(), 'path'));
	}

	public function test_an_unknown_action_is_not_found(): void {
		$this->assertSame(404, $this->get(['action' => 'refund'])->status);
		$this->assertSame(404, $this->get([])->status);
		$this->assertSame(404, $this->call(new \Monokulo\Whmcs\Request('POST', ['action' => 'pay']))->status);
	}
}
