<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The WHMCS install's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host: the connection is kept by the host of WHMCS's
 * System URL, so changing that URL means connecting again.
 *
 * `api` is the API's entry (`https://monokulo.example/api/v1`): every other
 * address the gateway calls is a link or form from there, resolved against
 * it, and the secret key picks the store.
 */
final class Connection {
	public function __construct(
		public readonly string $host,
		public readonly string $api,
		public readonly string $publicKey,
		public readonly string $secretToken,
		public readonly string $webhookSecret,
		public readonly int $connectedAt,
	) {
	}

	/**
	 * Where the gateway sends its warnings and errors. The entry doesn't
	 * link to it, so it is the one address made here: the entry's `logs`.
	 */
	public function logsUrl(): string {
		return rtrim($this->api, '/') . '/logs';
	}

	/** The order's page in the merchant's Monokulo, on the API's host. */
	public function dashboardOrderUrl(string $storeId, string $orderId): string {
		return Url::resolve($this->api, '/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId));
	}
}
