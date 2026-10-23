<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Handles one webhook delivery and says which HTTP status to answer.
 * Monokulo reads only the status: a 2xx is delivered, anything else is
 * retried with backoff.
 *
 * - 401: WHMCS isn't connected, or the signature doesn't check out (wrong
 *   secret, changed body, more than five minutes off).
 * - 400: correctly signed, but not a webhook envelope.
 * - 404: no invoice here for that Monokulo order (retried, in case it is
 *   still being saved).
 * - 200: applied, or already applied before (a repeat changes nothing),
 *   or an event this version doesn't know (accepted and logged).
 * - 500: applying it failed; the event is given back so the retry applies it.
 */
final class WebhookReceiver {
	public function __construct(
		private readonly Repository $repository,
		private readonly InvoiceUpdater $updater,
		private readonly Logger $log,
	) {
	}

	public function handle(?Connection $connection, string $body, string $signature, int $now): int {
		if (!$connection || !Signature::verify($connection->webhookSecret, $body, $signature, $now)) {
			$this->log->warning('Webhook refused: WHMCS is not connected, or a missing or wrong X-Monokulo-Signature.');

			return 401;
		}
		$event = WebhookEvent::parse($body);
		if (!$event) {
			$this->log->error('Webhook refused: signed, but not a webhook envelope: ' . $body);

			return 400;
		}
		$order = $this->repository->findOrder($event->orderId);
		if (!$order) {
			$this->log->warning(sprintf('Webhook %s for Monokulo order %s: no such order in this WHMCS.', $event->eventId, $event->orderId));

			return 404;
		}
		$decision = StatusPolicy::decide($event);
		if (!$decision) {
			$this->log->warning(sprintf('Webhook %s: unknown event "%s", accepted and ignored.', $event->eventId, $event->event));

			return 200;
		}
		if (!$this->repository->claimEvent($event->eventId, $event->orderId, $now)) {
			return 200;
		}
		try {
			$this->updater->apply($order, $decision, $event, $connection);
		} catch (\Throwable $e) {
			$this->repository->releaseEvent($event->eventId);
			$this->log->error(sprintf('Webhook %s for invoice %d could not be applied: %s', $event->eventId, $order['invoice_id'], $e->getMessage()));

			return 500;
		}
		$this->log->info(sprintf('Webhook %s (%s) applied to invoice %d.', $event->eventId, $event->event, $order['invoice_id']));

		return 200;
	}
}
