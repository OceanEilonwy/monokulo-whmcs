<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The WHMCS install's link to a store in Monokulo, from the connect flow. A
 * Monokulo store is one host: the connection is kept by the host of WHMCS's
 * System URL, so changing that URL means connecting again.
 *
 * `api` is the API's entry (`https://monokulo.example/api/v1`, or
 * `https://example.com/monokulo/api/v1` for a Monokulo served under a
 * path): every other address the gateway calls is a link or form from
 * there, and the secret key picks the store. `onionApi` is the same entry
 * on Monokulo's onion address, while it has one (null otherwise, and for a
 * shop connected before Monokulo handed it over, until the entry is read).
 */
final class Connection {
	public function __construct(
		public readonly string $host,
		public readonly string $api,
		public readonly string $publicKey,
		public readonly string $secretToken,
		public readonly string $webhookSecret,
		public readonly int $connectedAt,
		public readonly ?string $onionApi = null,
	) {
	}
}
