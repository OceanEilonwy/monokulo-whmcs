<?php
namespace Monokulo\Whmcs\Tests\Http;

use Monokulo\Whmcs\Order;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Browser;
use Monokulo\Whmcs\Tests\Support\Vectors;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * Webhooks as Monokulo delivers them: real HTTP posts to the callback file,
 * through the stand-in WHMCS's web server, with Monokulo's published bodies
 * byte for byte.
 *
 * The server's clock is today's, not the vectors' `sent_at`, so each body
 * is signed again now with the vectors' secret, exactly as Monokulo signs
 * (`t=<now>,v1=HMAC-SHA256(secret, "<now>.<body>")`). The published
 * signatures themselves are checked at `sent_at` by the unit and module
 * suites; here, being old, they must be refused.
 */
final class WebhookOverHttpTest extends TestCase {
	private int $invoice;

	protected function setUp(): void {
		Whmcs::reset();
		Whmcs::connect('https://pay.example.com', Vectors::secret());
		$this->invoice = Whmcs::invoice(['Hosting' => '100.00']);
		(new Repository(Capsule::connection()))->recordOrder(new Order(Vectors::orderId(), 'https://pay.example.com/checkout/' . Vectors::orderId()), $this->invoice, Whmcs::HOST, '100.00', 'USD', 0, 1760000000);
	}

	protected function tearDown(): void {
		Whmcs::activate();
	}

	/** @param array<string, string> $headers */
	private function postRaw(string $body, array $headers): \Monokulo\Whmcs\Tests\Support\Page {
		return (new Browser())->post(Whmcs::CALLBACK, $body, $headers + ['Content-Type' => 'application/json']);
	}

	/** Monokulo's delivery `name`, signed now. */
	private function deliver(string $name): int {
		$delivery = Vectors::delivery($name);
		$t = time();
		$headers = ['X-Monokulo-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $delivery['body'], Vectors::secret())] + $delivery['headers'];

		return $this->postRaw($delivery['body'], $headers)->status;
	}

	public function test_paid_over_http_pays_the_invoice(): void {
		$this->assertSame(200, $this->deliver('order.unconfirmed'));
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));

		$this->assertSame(200, $this->deliver('order.paid'));
		$this->assertSame('Paid', Whmcs::invoiceStatus($this->invoice));
		$this->assertSame(['Payment seen', 'Paid'], array_column(Whmcs::gatewayLog(), 'result'));
	}

	public function test_every_published_delivery_is_accepted_over_http(): void {
		foreach (array_keys(Vectors::deliveries()) as $name) {
			$this->assertSame(200, $this->deliver($name), $name);
		}
		$this->assertCount(1, Whmcs::payments($this->invoice));
		$this->assertCount(count(Vectors::orderDeliveries()), Whmcs::gatewayLog());
		$this->assertSame('evt_0000000000000012', (new Repository(Capsule::connection()))->lastTestWebhook()['event_id'] ?? null, 'the test delivery is kept');
	}

	/** Its own (old) signature: too late now. */
	public function test_a_published_signature_is_too_old_over_http(): void {
		$delivery = Vectors::delivery('order.paid');

		$page = $this->postRaw($delivery['body'], $delivery['headers']);

		$this->assertSame(401, $page->status);
		$this->assertSame('401', $page->body);
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
	}

	public function test_an_unsigned_delivery_is_refused_over_http(): void {
		$this->assertSame(401, $this->postRaw(Vectors::delivery('order.paid')['body'], [])->status);
	}

	/** Deactivated in WHMCS: the callback says so, and Monokulo retries until it is back. */
	public function test_a_deactivated_gateway_takes_nothing_over_http(): void {
		Whmcs::deactivate();

		$this->assertSame(503, $this->deliver('order.paid'));
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($this->invoice));
	}

	public function test_a_get_without_an_action_is_not_found_over_http(): void {
		$this->assertSame(404, (new Browser())->get(Whmcs::CALLBACK)->status);
	}
}
