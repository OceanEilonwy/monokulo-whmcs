<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Links;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Repository;
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

	private function monokuloCreates(string $orderId = 'ord_1', int $status = 200): void {
		self::$monokulo->respond('POST', '/pay/pk_test/orders', $status, $status === 200 ? ['order_id' => $orderId, 'address' => '4…', 'expires_at' => 1] : ['error' => 'nope']);
	}

	/** @return array<string, mixed> */
	private function sent(int $i = 0): array {
		return json_decode(self::$monokulo->requests()[$i]['body'], true);
	}

	public function test_the_client_is_sent_to_pay_the_invoice_balance(): void {
		$invoice = Whmcs::invoice(['Starter - example.com' => '80.00', 'Domain renewal' => '15.00', 'SSL' => '5.50'], 'EUR');
		$this->monokuloCreates();

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(303, $response->status);
		$this->assertSame(self::$monokulo->url . '/pay/pk_test/orders/ord_1', $response->headers['Location']);
		$this->assertSame([
			'amount'                 => '100.50',
			'currency'               => 'EUR',
			'merchant_order_id'      => (string)$invoice,
			'show_merchant_order_id' => true,
			'description'            => 'Invoice #' . $invoice . ': Starter - example.com + 2 more',
			'idempotency_key'        => 'whmcs:' . $invoice . ':100.50:EUR:0',
		], $this->sent());
		$request = self::$monokulo->requests()[0];
		$this->assertSame('Bearer sk_test', $request['headers']['authorization']);
		$this->assertSame('whmcs/' . Plugin::VERSION, $request['headers']['monokulo-client']);

		$this->assertSame([['monokulo_order_id' => 'ord_1', 'invoice_id' => $invoice, 'amount' => '100.50', 'currency' => 'EUR', 'attempt' => 0, 'expired' => 0]], array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['monokulo_order_id', 'invoice_id', 'amount', 'currency', 'attempt', 'expired'])), Whmcs::rows('mod_monokulo_order')));
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($invoice), 'nothing paid yet');
		$log = Whmcs::gatewayLog();
		$this->assertCount(1, $log);
		$this->assertSame('Sent to pay', $log[0]['result']);
		$this->assertStringContainsString('Client sent to pay with Monero: ' . self::$monokulo->url . '/pay/pk_test/orders/ord_1', $log[0]['data']);
	}

	/** Part paid already (by credit, say): only the balance is asked for. */
	public function test_only_the_balance_is_asked_for(): void {
		$invoice = Whmcs::invoice(['Hosting' => '100.00']);
		addInvoicePayment($invoice, 'credit-1', '30', 0, 'credit');
		$this->monokuloCreates();

		$this->get($this->payLink($invoice));

		$this->assertSame('70.00', $this->sent()['amount']);
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
		$this->monokuloCreates('ord_1');
		$this->get($this->payLink($invoice));
		(new Repository(Capsule::connection()))->markExpired('ord_1');
		$this->monokuloCreates('ord_2');

		$this->assertSame(self::$monokulo->url . '/pay/pk_test/orders/ord_2', $this->get($this->payLink($invoice))->headers['Location']);

		$this->assertSame('whmcs:' . $invoice . ':100.00:USD:1', $this->sent(1)['idempotency_key']);
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
			'rate limited' => [429, 'Monero payments are busy right now', 'rate limited'],
			'key refused'  => [401, 'Monero payments are not available right now', 'connect again from the Monokulo gateway settings'],
			'unknown key'  => [404, 'could not be started', 'connect the store again'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('monokuloFailures')]
	public function test_when_monokulo_refuses_the_client_is_told_and_the_merchant_given_a_hint(int $status, string $client, string $hint): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(status: $status);

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(502, $response->status);
		$this->assertStringContainsString($client, $response->body);
		$this->assertStringContainsString('Back to the invoice', $response->body);
		$this->assertStringContainsString('Creating the Monokulo order for invoice ' . $invoice . ' failed: HTTP ' . $status, Whmcs::logs());
		$this->assertStringContainsString($hint, Whmcs::logs());
		$this->assertSame([], Whmcs::rows('mod_monokulo_order'));
	}

	public function test_an_unreachable_monokulo_is_reported(): void {
		Capsule::table('mod_monokulo_connection')->update(['endpoint' => 'http://127.0.0.1:' . FakeMonokulo::freePort()]);
		$invoice = Whmcs::invoice();

		$response = $this->get($this->payLink($invoice));

		$this->assertSame(502, $response->status);
		$this->assertStringContainsString('could not be reached', $response->body);
		$this->assertStringContainsString('could not reach', Whmcs::logs());
	}

	/** With "Send errors to Monokulo" on, the merchant's warning goes to Monokulo too, with its trace. */
	public function test_errors_are_forwarded_when_the_merchant_asked(): void {
		Whmcs::setting('forwardErrors', 'on');
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(status: 429);
		self::$monokulo->respond('POST', '/pay/pk_test/logs', 204, '');

		$this->get($this->payLink($invoice));

		$requests = self::$monokulo->requests();
		$this->assertSame('/pay/pk_test/logs', $requests[1]['path']);
		$entries = json_decode($requests[1]['body'], true)['entries'];
		$this->assertSame('error', $entries[0]['level']);
		$this->assertStringContainsString('HTTP 429', $entries[0]['message']);
		$this->assertStringContainsString('[trace ' . $entries[0]['trace_id'] . ']', Whmcs::logs());
		$this->assertStringContainsString('00-' . $entries[0]['trace_id'] . '-', $requests[0]['headers']['traceparent'], 'the order request carried the same trace');
	}

	public function test_errors_stay_in_whmcs_otherwise(): void {
		$invoice = Whmcs::invoice();
		$this->monokuloCreates(status: 429);

		$this->get($this->payLink($invoice));

		$this->assertCount(1, self::$monokulo->requests());
	}

	public function test_an_unknown_action_is_not_found(): void {
		$this->assertSame(404, $this->get(['action' => 'refund'])->status);
		$this->assertSame(404, $this->get([])->status);
		$this->assertSame(404, $this->call(new \Monokulo\Whmcs\Request('POST', ['action' => 'pay']))->status);
	}
}
