<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Maps a monokulo event to what happens to the WHMCS invoice.
 *
 * - `order.unconfirmed`, `order.confirming`: a payment was seen and is
 *   waiting for confirmations. Gateway log only; the invoice stays Unpaid.
 * - `order.partial`: less than the amount due has arrived. Gateway log only.
 * - `order.paid`: paid in full. The payment is added to the invoice
 *   (WHMCS marks it Paid, sends its payment confirmation email and runs
 *   whatever paying an invoice runs, such as provisioning).
 * - `order.overpaid`: paid with more than was due. As paid, and a note that
 *   the excess isn't refunded automatically.
 * - `order.expired`: the payment window closed without full payment. The
 *   invoice stays Unpaid; paying it again starts a new Monokulo order.
 * - `order.cancelled`: the merchant cancelled the order before anything was
 *   paid (only the POS cancels, its own orders, so an order WHMCS made isn't
 *   expected to be). As expired: it takes no payment any more.
 * - `order.double_spend_detected`, `order.double_spend_reversed`: gateway
 *   log only. If the order's status changes because of it, monokulo
 *   announces that as its own `order.<status>` event.
 *
 * Anything else is accepted and ignored (null), so a newer monokulo can add
 * events without breaking WHMCS.
 */
final class StatusPolicy {
	public static function decide(WebhookEvent $event): ?Decision {
		return match ($event->event) {
			'order.unconfirmed', 'order.confirming' => new Decision('Payment seen', 'note_waiting'),
			'order.partial'                         => new Decision('Part paid', 'note_partial'),
			'order.paid'                            => new Decision('Paid', 'note_paid', paid: true),
			'order.overpaid'                        => new Decision('Overpaid', 'note_overpaid', paid: true),
			'order.expired'                         => new Decision('Expired', 'note_expired', expired: true),
			'order.cancelled'                       => new Decision('Cancelled', 'note_cancelled', expired: true),
			'order.double_spend_detected'           => new Decision('Double spend', 'note_double_spend_detected'),
			'order.double_spend_reversed'           => new Decision('Double spend withdrawn', 'note_double_spend_reversed'),
			default                                 => null,
		};
	}
}
