<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Order;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Calls;
use Monokulo\Whmcs\Tests\Support\Vectors;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use Monokulo\Whmcs\Tests\Support\WhmcsTestCase;
use WHMCS\Database\Capsule;

/**
 * Monokulo's published webhook deliveries, byte for byte, through the
 * callback against WHMCS's invoices, payments and gateway log. The clock is
 * the vectors' `sent_at`, so their own signatures check out.
 */
final class WebhookTest extends WhmcsTestCase {
	use Calls;

	private int $invoice;

	protected function setUp(): void {
		parent::setUp();
		Whmcs::connect('https://pay.example.com', Vectors::secret());
		$this->invoice = Whmcs::invoice(['Hosting' => '100.00']);
		// As Pay with Monero leaves it: the invoice mapped to the Monokulo order the vectors are about.
		$this->repository()->recordOrder(new Order(Vectors::orderId(), 'https://pay.example.com/checkout/' . Vectors::orderId(), null, 'https://pay.example.com/monokulo/stores/st_1/orders/' . Vectors::orderId()), $this->invoice, Whmcs::HOST, '100.00', 'USD', 0, 1760000000);
	}

	private function repository(): Repository {
		return new Repository(Capsule::connection());
	}

	private function deliver(string $body, string $signature, ?int $now = null): int {
		return $this->post($body, $signature, $now ?? Vectors::sentAt())->status;
	}

	private function deliverVector(string $name): int {
		$delivery = Vectors::delivery($name);

		return $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']);
	}

	/** @return array{data: string, result: string} */
	private function lastLog(): array {
		$log = Whmcs::gatewayLog();

		return end($log);
	}

	private function signed(string $body): string {
		$t = Vectors::sentAt();

		return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, Vectors::secret());
	}

	public function test_a_payment_seen_is_logged_and_the_invoice_waits(): void {
		$this->assertSame(200, $this->deliverVector('order.unconfirmed'));
		$this->assertSame('Payment seen', $this->lastLog()['result']);
		$this->assertStringContainsString('waiting for confirmations', $this->lastLog()['data']);

		$this->assertSame(200, $this->deliverVector('order.confirming'));
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame([], Whmcs::payments($this->invoice));
	}

	public function test_part_paid_is_logged_and_adds_nothing(): void {
		$this->assertSame(200, $this->deliverVector('order.partial'));
		$this->assertSame('Part paid', $this->lastLog()['result']);
		$this->assertStringContainsString('Part of the amount due has arrived', $this->lastLog()['data']);
		$this->assertSame([], Whmcs::payments($this->invoice));
	}

	/** Paid: the payment on the invoice under the Monokulo order's id, and the log with the order's mk:dashboard-order link, as kept. */
	public function test_paid_adds_the_payment_and_the_invoice_is_paid(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
		$payment = Whmcs::payments($this->invoice)[0];
		$this->assertSame(['monokulo', Vectors::orderId(), '100.00', '0.00'], [$payment['gateway'], $payment['transid'], $payment['amountin'], $payment['fees']]);
		$log = $this->lastLog();
		$this->assertSame('Paid', $log['result']);
		$this->assertStringContainsString('invoice => ' . $this->invoice, $log['data']);
		$this->assertStringContainsString('event => order.paid', $log['data']);
		$this->assertStringContainsString('amount => 100.00 USD', $log['data']);
		$this->assertStringContainsString('in_monokulo => https://pay.example.com/monokulo/stores/st_1/orders/' . Vectors::orderId(), $log['data']);
	}

	/** An order made before its dashboard link was kept (0.1.0): the log has no link rather than a built one. */
	public function test_an_order_without_a_kept_dashboard_link_logs_none(): void {
		Capsule::table('mod_monokulo_order')->update(['dashboard_url' => null]);

		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame('Paid', $this->lastLog()['result']);
		$this->assertStringNotContainsString('in_monokulo', $this->lastLog()['data']);
	}

	public function test_paid_in_xmr_is_paid_too(): void {
		$this->assertSame(200, $this->deliverVector('order.paid priced in XMR'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
	}

	public function test_overpaid_is_paid_with_a_note_about_the_excess(): void {
		$this->assertSame(200, $this->deliverVector('order.overpaid'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame('Overpaid', $this->lastLog()['result']);
		$this->assertStringContainsString('the excess is not refunded automatically', $this->lastLog()['data']);
	}

	/** Paid, then overpaid (a second payment came in): the payment is on the invoice once. */
	public function test_paid_then_overpaid_adds_the_payment_once(): void {
		$this->deliverVector('order.paid');
		$this->assertSame(200, $this->deliverVector('order.overpaid'));

		$this->assertCount(1, Whmcs::payments($this->invoice));
		$this->assertStringContainsString('already on the invoice, so nothing was added: refund the excess yourself', $this->lastLog()['data']);
	}

	/** Expired: the invoice stays Unpaid, and the next Pay with Monero is a new attempt. */
	public function test_expired_leaves_the_invoice_unpaid_for_a_new_attempt(): void {
		$this->assertSame(200, $this->deliverVector('order.expired'));

		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame('Expired', $this->lastLog()['result']);
		$this->assertStringContainsString('Any funds received stay at the payment address', $this->lastLog()['data']);
		$this->assertSame(1, $this->repository()->attempt($this->invoice));
	}

	/** A double spend is a log entry only: the status change, if any, comes as its own event. */
	public function test_double_spend_alerts_are_log_entries_only(): void {
		$this->deliverVector('order.paid');
		$this->assertSame(200, $this->deliverVector('order.double_spend_detected'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame('Double spend', $this->lastLog()['result']);
		$this->assertStringContainsString('FRAUD ALERT', $this->lastLog()['data']);
		$this->assertMatchesRegularExpression('/transaction [0-9a-f]{64}/', $this->lastLog()['data']);
		$this->assertMatchesRegularExpression('/txid => [0-9a-f]{64}/', $this->lastLog()['data']);

		$this->assertSame(200, $this->deliverVector('order.double_spend_reversed'));
		$this->assertSame('Double spend withdrawn', $this->lastLog()['result']);
		$this->assertStringContainsString('was wrong and is withdrawn', $this->lastLog()['data']);
		$this->assertCount(1, Whmcs::payments($this->invoice));
	}

	/** Every published delivery, in order, on one invoice: each logged once, one payment. */
	public function test_every_published_delivery_is_accepted(): void {
		foreach (Vectors::deliveries() as $name => $delivery) {
			$this->assertSame(200, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature']), $name);
		}
		$this->assertCount(count(Vectors::deliveries()), Whmcs::gatewayLog());
		$this->assertCount(1, Whmcs::payments($this->invoice));
	}

	/** A delivery whose answer was lost is sent again; the second changes nothing. */
	public function test_a_repeated_delivery_changes_nothing(): void {
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertCount(1, Whmcs::gatewayLog());
		$this->assertCount(1, Whmcs::payments($this->invoice));
	}

	public function test_every_published_forgery_is_refused_and_changes_nothing(): void {
		foreach (Vectors::refused() as $why => $refuse) {
			$this->assertSame(401, $this->deliver($refuse['body'], $refuse['headers']['X-Monokulo-Signature']), $why);
		}
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame([], Whmcs::gatewayLog());
		$this->assertStringContainsString('Webhook refused', Whmcs::logs());
	}

	public function test_a_genuine_delivery_arriving_too_late_is_refused(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->assertSame(401, $this->deliver($delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt() + 301));
	}

	public function test_a_whmcs_that_never_connected_refuses_everything(): void {
		Capsule::table('mod_monokulo_connection')->delete();

		$this->assertSame(401, $this->deliverVector('order.paid'));
	}

	public function test_signed_but_not_an_envelope_is_a_bad_request(): void {
		$this->assertSame(400, $this->deliver('{"hello":"world"}', $this->signed('{"hello":"world"}')));
	}

	/** Retried, in case the order is still being saved. */
	public function test_an_order_this_whmcs_never_made_is_not_found(): void {
		Capsule::table('mod_monokulo_order')->delete();

		$this->assertSame(404, $this->deliverVector('order.paid'));
	}

	public function test_an_event_this_version_does_not_know_is_accepted_and_ignored(): void {
		$body = (string)json_encode(['api_version' => 2, 'event_id' => 'evt_new', 'event' => 'order.refunded', 'created_at' => 1, 'order_id' => Vectors::orderId()]);

		$this->assertSame(200, $this->deliver($body, $this->signed($body)));
		$this->assertSame([], Whmcs::gatewayLog());
		$this->assertStringContainsString('unknown event "order.refunded"', Whmcs::logs());
	}

	/**
	 * The balance changed after a first payment request, so a newer one
	 * exists; Monokulo still watches the older one. Paid all the same: the
	 * money arrived, so the payment is added, and the merchant is asked to
	 * check the amount.
	 */
	public function test_paying_an_older_request_pays_the_invoice(): void {
		$this->repository()->recordOrder(new Order('order_newer', 'https://pay.example.com/checkout/order_newer'), $this->invoice, Whmcs::HOST, '120', 'USD', 0, 1760000001);

		$this->assertSame(200, $this->deliverVector('order.paid'));

		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame([Vectors::orderId()], array_column(Whmcs::payments($this->invoice), 'transid'));
		$this->assertSame('Paid', $this->lastLog()['result']);
		$this->assertStringContainsString('This was an older payment request for this invoice', $this->lastLog()['data']);
	}

	/** Anything but a payment, from an order that isn't the invoice's newest, is a log entry only. */
	public function test_other_events_from_an_older_request_are_log_entries_only(): void {
		$this->repository()->recordOrder(new Order('order_newer', 'https://pay.example.com/checkout/order_newer'), $this->invoice, Whmcs::HOST, '120', 'USD', 0, 1760000001);

		foreach (['order.unconfirmed' => 'Payment seen', 'order.partial' => 'Part paid', 'order.expired' => 'Expired', 'order.double_spend_detected' => 'Double spend'] as $vector => $result) {
			$this->assertSame(200, $this->deliverVector($vector));
			$this->assertSame('Older order: ' . $result, $this->lastLog()['result'], $vector);
			$this->assertStringContainsString('not its current one: nothing on the invoice changed', $this->lastLog()['data']);
		}
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame([], Whmcs::payments($this->invoice));
	}

	/** A paid invoice never goes back, whatever its Monokulo order says after. */
	public function test_a_paid_invoice_never_goes_back(): void {
		$this->deliverVector('order.paid');

		foreach (['order.partial', 'order.expired', 'order.double_spend_detected', 'order.confirming'] as $vector) {
			$this->assertSame(200, $this->deliverVector($vector));
			$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice), $vector);
		}
		$this->assertCount(1, Whmcs::payments($this->invoice));
	}

	/**
	 * Paid already, by the invoice's newer Monokulo order, when the older one
	 * is paid too: nothing is added, and the merchant is told to refund it.
	 */
	public function test_a_second_payment_to_a_paid_invoice_is_logged_for_a_refund(): void {
		$this->repository()->recordOrder(new Order('order_newer', 'https://pay.example.com/checkout/order_newer'), $this->invoice, Whmcs::HOST, '100', 'USD', 1, 1760000001);
		addInvoicePayment($this->invoice, 'order_newer', '100', 0, 'monokulo');
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));

		foreach (['order.paid', 'order.overpaid'] as $vector) {
			$this->assertSame(200, $this->deliverVector($vector));
			$this->assertSame('Paid again', $this->lastLog()['result'], $vector);
			$this->assertStringContainsString('This is a second payment: refund it from your wallet if needed.', $this->lastLog()['data']);
		}
		$this->assertSame(['order_newer'], array_column(Whmcs::payments($this->invoice), 'transid'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
	}

	/** Applying fails (the invoice was deleted): 500, and the event is given back so a retry applies it. */
	public function test_a_deleted_invoice_is_retried(): void {
		$row = Whmcs::rows('tblinvoices')[0];
		Capsule::table('tblinvoices')->delete();

		$this->assertSame(500, $this->deliverVector('order.paid'));
		$this->assertStringContainsString('invoice ' . $this->invoice . ' no longer exists', Whmcs::logs());

		Capsule::table('tblinvoices')->insert($row);
		$this->assertSame(200, $this->deliverVector('order.paid'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
	}

	/** Monokulo's trace is joined: the log names the trace Monokulo's own logs have. */
	public function test_the_webhooks_trace_is_joined(): void {
		$delivery = Vectors::delivery('order.paid');

		$this->post($delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt(), '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

		$this->assertStringContainsString('applied to invoice ' . $this->invoice . '. [trace 4bf92f3577b34da6a3ce929d0e0e4736]', Whmcs::logs());
	}
}
