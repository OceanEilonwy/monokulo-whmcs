<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The connect flow's first step: where the admin's browser goes to sign in
 * to Monokulo and pick (or set up) the store for this shop.
 */
final class ConnectLink {
	/**
	 * `{address}/plugins/connect/whmcs?site_url=…&return_url=…&nonce=…&name=WHMCS`.
	 * `returnUrl` must be on the same host as `siteUrl` and https (plain http
	 * only for localhost or a .onion host): Monokulo refuses anything else.
	 */
	public static function start(string $address, string $siteUrl, string $returnUrl, string $nonce): string {
		return rtrim($address, '/') . '/plugins/connect/' . Plugin::SLUG . '?' . http_build_query([
			'site_url'   => $siteUrl,
			'return_url' => $returnUrl,
			'nonce'      => $nonce,
			'name'       => Plugin::NAME,
		], '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * The Monokulo address the merchant typed, cleaned (no trailing slash),
	 * or null when it isn't an http(s) address.
	 */
	public static function address(string $typed): ?string {
		$typed = trim($typed);
		$parts = parse_url($typed);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
			|| isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
			return null;
		}

		return rtrim($typed, '/');
	}

	/** The host of a shop address (`https://Shop.example/` → `shop.example`). */
	public static function host(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		$port = parse_url($url, PHP_URL_PORT);

		return strtolower(is_string($host) ? $host : '') . (is_int($port) ? ':' . $port : '');
	}
}
