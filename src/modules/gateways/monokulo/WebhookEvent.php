<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The parts of a webhook body (api_version 2) this plugin acts on. Fields it
 * doesn't need are ignored, and fields monokulo adds later don't break it.
 *
 * `dashboardUrl` is the order's page on the merchant's dashboard, the
 * body's `_links` `mk:dashboard-order` (absolute: a webhook has no request
 * to resolve a path against), or null when the body has none.
 */
final class WebhookEvent {
	private function __construct(
		public readonly string $event,
		public readonly string $eventId,
		public readonly string $orderId,
		public readonly ?string $txid,
		public readonly ?string $dashboardUrl,
	) {
	}

	/** The event in `body`, or null when it isn't a webhook envelope. */
	public static function parse(string $body): ?self {
		$data = json_decode($body, true);
		if (!is_array($data)) {
			return null;
		}
		foreach (['event', 'event_id', 'order_id'] as $key) {
			if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
				return null;
			}
		}
		$text = static fn(string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;
		$dashboard = $data['_links']['mk:dashboard-order']['href'] ?? null;
		$absolute = is_string($dashboard) && in_array(strtolower((string)parse_url($dashboard, PHP_URL_SCHEME)), ['http', 'https'], true);

		return new self($data['event'], $data['event_id'], $data['order_id'], $text('txid'), $absolute ? $dashboard : null);
	}
}
