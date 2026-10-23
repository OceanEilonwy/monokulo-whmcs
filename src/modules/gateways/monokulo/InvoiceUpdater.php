<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Applies one webhook decision to a WHMCS invoice: a payment added (WHMCS
 * then does what it does when an invoice is paid: the confirmation email,
 * provisioning) and an entry in the gateway log either way.
 *
 * An invoice can have several Monokulo orders (its balance changed, or an
 * earlier one expired), and Monokulo keeps watching all of them:
 *
 * - Paid (or overpaid), from any of them: the money arrived, so the payment
 *   is added, unless this order's payment is already on the invoice or the
 *   invoice is already paid. Those are log entries only, so the merchant can
 *   refund a second payment: a paid invoice never goes back.
 * - Anything else from an order that isn't the invoice's newest: a log
 *   entry only, marked as about an older payment request.
 */
final class InvoiceUpdater {
	public function __construct(
		private readonly Whmcs $whmcs,
		private readonly Repository $repository,
	) {
	}

	/**
	 * @param array{invoice_id: int, amount: string, currency: string, latest: bool} $order the Monokulo order, as Repository::findOrder has it
	 */
	public function apply(array $order, Decision $decision, WebhookEvent $event, Connection $connection): void {
		$invoiceId = $order['invoice_id'];
		$invoice = $this->whmcs->invoice($invoiceId);
		if (!$invoice) {
			throw new \RuntimeException('invoice ' . $invoiceId . ' no longer exists');
		}

		$note = $decision->note;
		$result = $decision->result;
		$older = '';
		if ($decision->paid && $this->whmcs->paymentRecorded($event->orderId)) {
			$note = $event->event === 'order.overpaid' ? 'note_overpaid_after_paid' : 'note_already_recorded';
		} elseif ($decision->paid && $invoice->status === 'Paid') {
			// Paid already (by another of its Monokulo orders, or otherwise):
			// this is a second payment, for the merchant to refund.
			$note = 'note_paid_again';
			$result = 'Paid again';
		} elseif ($decision->paid) {
			$this->whmcs->addPayment($invoiceId, $event->orderId, $order['amount']);
			if (!$order['latest']) {
				$older = Text::get('note_paid_older');
			}
		} elseif (!$order['latest']) {
			$older = Text::get('note_older');
			$result = 'Older order: ' . $result;
		}
		if ($decision->expired) {
			$this->repository->markExpired($event->orderId);
		}

		$text = Text::get($note);
		if (str_contains($text, '%s')) {
			$text = sprintf($text, $event->txid ?? Text::get('note_unknown_transaction'));
		}
		$data = [
			'invoice'        => (string)$invoiceId,
			'event'          => $event->event,
			'event_id'       => $event->eventId,
			'monokulo_order' => $event->orderId,
			'amount'         => $order['amount'] . ' ' . $order['currency'],
			'note'           => $text . ($older !== '' ? ' ' . $older : ''),
		];
		if ($event->txid) {
			$data['txid'] = $event->txid;
		}
		if ($event->storeId) {
			$data['in_monokulo'] = $connection->dashboardOrderUrl($event->storeId, $event->orderId);
		}
		$this->whmcs->gatewayLog($data, $result);
	}
}
