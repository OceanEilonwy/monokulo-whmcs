<?php
namespace Monokulo\Whmcs;

/**
 * Applies one webhook decision to a WHMCS invoice: a payment added (WHMCS
 * then does what it does when an invoice is paid: the confirmation email,
 * provisioning) and an entry in the gateway log either way.
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
		if (!$this->whmcs->invoice($invoiceId)) {
			throw new \RuntimeException('invoice ' . $invoiceId . ' no longer exists');
		}

		$note = $decision->note;
		$result = $decision->result;
		if ($decision->paid && !$order['latest']) {
			// Paid, but not the invoice's newest payment request: the balance
			// changed since. Tell the merchant instead of adding it.
			$note = 'note_superseded';
			$result = 'Superseded';
		} elseif ($decision->paid && $this->whmcs->paymentRecorded($event->orderId)) {
			$note = 'note_already_recorded';
		} elseif ($decision->paid) {
			$this->whmcs->addPayment($invoiceId, $event->orderId, $order['amount']);
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
			'note'           => $text,
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
