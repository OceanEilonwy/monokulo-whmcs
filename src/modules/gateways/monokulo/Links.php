<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The gateway's own links into its callback, signed with the install's link
 * key so that only the pages that hand them out can make them:
 *
 * - Pay with Monero, on an invoice the client can see (and in the invoice
 *   email): `?action=pay&invoice=<id>&sig=…`. It never expires, as an
 *   emailed invoice may be paid days later.
 * - Connect, on the gateway's settings page: `?action=connect&return=…&t=…&sig=…`,
 *   good for an hour, and only for a browser signed in to the admin area.
 */
final class Links {
	public const CALLBACK = 'modules/gateways/callback/monokulo.php';
	public const CONNECT_TTL_SECONDS = 3600;

	public static function callback(string $systemUrl): string {
		return $systemUrl . self::CALLBACK;
	}

	public static function pay(string $systemUrl, string $key, int $invoiceId): string {
		return self::callback($systemUrl) . '?' . http_build_query([
			'action'  => 'pay',
			'invoice' => $invoiceId,
			'sig'     => self::sign($key, 'pay:' . $invoiceId),
		], '', '&', PHP_QUERY_RFC3986);
	}

	public static function payIsGenuine(string $key, int $invoiceId, string $sig): bool {
		return $invoiceId > 0 && hash_equals(self::sign($key, 'pay:' . $invoiceId), $sig);
	}

	public static function connect(string $systemUrl, string $key, string $return, int $now): string {
		return self::callback($systemUrl) . '?' . http_build_query([
			'action' => 'connect',
			'return' => $return,
			't'      => $now,
			'sig'    => self::sign($key, 'connect:' . $now . ':' . $return),
		], '', '&', PHP_QUERY_RFC3986);
	}

	public static function connectIsGenuine(string $key, string $return, int $t, string $sig, int $now): bool {
		return $t <= $now && $now - $t <= self::CONNECT_TTL_SECONDS && hash_equals(self::sign($key, 'connect:' . $t . ':' . $return), $sig);
	}

	/** `url` with `query` added to its query string. */
	public static function with(string $url, string $query): string {
		return $url . (str_contains($url, '?') ? '&' : '?') . $query;
	}

	private static function sign(string $key, string $what): string {
		return hash_hmac('sha256', $what, $key);
	}
}
