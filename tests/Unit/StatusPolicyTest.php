<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\StatusPolicy;
use Monokulo\Whmcs\Tests\Support\Vectors;
use Monokulo\Whmcs\WebhookEvent;
use Monokulo\Whmcs\WebhookReceiver;
use PHPUnit\Framework\TestCase;

/**
 * The invoice table the README documents, checked against every event in
 * Monokulo's published vectors.
 */
final class StatusPolicyTest extends TestCase {
	private const TABLE = [
		// event                        gateway log result         note                          paid   expired
		'order.unconfirmed'           => ['Payment seen', 'note_waiting', false, false],
		'order.confirming'            => ['Payment seen', 'note_waiting', false, false],
		'order.partial'               => ['Part paid', 'note_partial', false, false],
		'order.paid'                  => ['Paid', 'note_paid', true, false],
		'order.overpaid'              => ['Overpaid', 'note_overpaid', true, false],
		'order.expired'               => ['Expired', 'note_expired', false, true],
		'order.cancelled'             => ['Cancelled', 'note_cancelled', false, true],
		'order.double_spend_detected' => ['Double spend', 'note_double_spend_detected', false, false],
		'order.double_spend_reversed' => ['Double spend withdrawn', 'note_double_spend_reversed', false, false],
	];

	public function test_every_published_event_follows_the_table(): void {
		$seen = [];
		foreach (Vectors::deliveries() as $name => $delivery) {
			$event = WebhookEvent::parse($delivery['body']);
			if ($event->event === WebhookReceiver::TEST) {
				// The receiver's own: it changes no invoice.
				$this->assertNull(StatusPolicy::decide($event), $name);
				continue;
			}
			$this->assertArrayHasKey($event->event, self::TABLE, "Monokulo publishes $name, which the table doesn't cover");
			[$result, $note, $paid, $expired] = self::TABLE[$event->event];
			$decision = StatusPolicy::decide($event);

			$this->assertSame([$result, $note, $paid, $expired], [$decision->result, $decision->note, $decision->paid, $decision->expired], $name);
			$seen[$event->event] = true;
		}

		$this->assertSame(array_keys(self::TABLE), array_keys(array_intersect_key(self::TABLE, $seen)), 'every row of the table is in the vectors');
	}

	/** A newer Monokulo's event is accepted and changes nothing. */
	public function test_an_unknown_event_does_nothing(): void {
		$this->assertNull(StatusPolicy::decide(WebhookEvent::parse('{"event":"order.refunded","event_id":"evt_9","order_id":"o1"}')));
	}
}
