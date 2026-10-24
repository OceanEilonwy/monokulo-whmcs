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
 * - Test connection, beside it: `?action=test&return=…&t=…&sig=…`, the same.
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
		return self::adminIsGenuine('connect', $key, $return, $t, $sig, $now);
	}

	public static function test(string $systemUrl, string $key, string $return, int $now): string {
		return self::callback($systemUrl) . '?' . http_build_query([
			'action' => 'test',
			'return' => $return,
			't'      => $now,
			'sig'    => self::sign($key, 'test:' . $now . ':' . $return),
		], '', '&', PHP_QUERY_RFC3986);
	}

	public static function testIsGenuine(string $key, string $return, int $t, string $sig, int $now): bool {
		return self::adminIsGenuine('test', $key, $return, $t, $sig, $now);
	}

	private static function adminIsGenuine(string $action, string $key, string $return, int $t, string $sig, int $now): bool {
		return $t <= $now && $now - $t <= self::CONNECT_TTL_SECONDS && hash_equals(self::sign($key, $action . ':' . $t . ':' . $return), $sig);
	}

	/**
	 * Whether an origin (`http://shop….onion`) is an onion address: a client
	 * there is on Tor, and must stay on it.
	 */
	public static function isOnion(string $origin): bool {
		$host = parse_url($origin, PHP_URL_HOST);

		return is_string($host) && str_ends_with(strtolower(rtrim($host, '.')), '.onion');
	}

	/**
	 * Where the shop is for a client who came in at `origin`: the System URL,
	 * or, for a client on the shop's onion address, the same path there (a
	 * client on Tor is never sent to the shop's web address).
	 */
	public static function shopUrl(string $systemUrl, string $origin): string {
		if (!self::isOnion($origin)) {
			return $systemUrl;
		}
		$path = parse_url($systemUrl, PHP_URL_PATH);

		return rtrim($origin, '/') . (is_string($path) && $path !== '' ? $path : '/');
	}

	/** `url` with `query` added to its query string. */
	public static function with(string $url, string $query): string {
		return $url . (str_contains($url, '?') ? '&' : '?') . $query;
	}

	private static function sign(string $key, string $what): string {
		return hash_hmac('sha256', $what, $key);
	}
}
