<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The WHMCS install's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host: the connection is kept by the host of WHMCS's
 * System URL, so changing that URL means connecting again.
 */
final class Connection {
	public function __construct(
		public readonly string $host,
		public readonly string $endpoint,
		public readonly string $publicKey,
		public readonly string $secretToken,
		public readonly string $webhookSecret,
		public readonly int $connectedAt,
	) {
	}

	public function ordersUrl(): string {
		return rtrim($this->endpoint, '/') . '/pay/' . rawurlencode($this->publicKey) . '/orders';
	}

	/** Where the customer pays the order. */
	public function checkoutUrl(string $orderId): string {
		return $this->ordersUrl() . '/' . rawurlencode($orderId);
	}

	public function logsUrl(): string {
		return rtrim($this->endpoint, '/') . '/pay/' . rawurlencode($this->publicKey) . '/logs';
	}

	/** The order's page in the merchant's Monokulo dashboard. */
	public function dashboardOrderUrl(string $storeId, string $orderId): string {
		return rtrim($this->endpoint, '/') . '/dashboard/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId);
	}
}
