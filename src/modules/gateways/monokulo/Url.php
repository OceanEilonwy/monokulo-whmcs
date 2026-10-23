<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Monokulo's links are paths on its own host (`/api/v1/orders`), resolved
 * against the address the gateway called (RFC 3986, section 5.2).
 */
final class Url {
	/** `$reference` resolved against the absolute `$base`. */
	public static function resolve(string $base, string $reference): string {
		if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $reference)) {
			return $reference;
		}
		$parts = parse_url($base);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return $reference;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($reference, '//')) {
			return $parts['scheme'] . ':' . $reference;
		}
		if (str_starts_with($reference, '/')) {
			return $origin . $reference;
		}
		$path = $parts['path'] ?? '/';
		if ($reference === '' || $reference[0] === '?' || $reference[0] === '#') {
			return $origin . $path . $reference;
		}
		$directory = substr($path, 0, (int)strrpos($path, '/') + 1);

		return $origin . self::removeDots(($directory === '' ? '/' : $directory) . $reference);
	}

	private static function removeDots(string $path): string {
		[$path, $rest] = array_pad(preg_split('/(?=[?#])/', $path, 2) ?: [$path], 2, '');
		$out = [];
		$segments = explode('/', $path);
		foreach ($segments as $i => $segment) {
			if ($segment === '.') {
				if ($i === count($segments) - 1) {
					$out[] = '';
				}
			} elseif ($segment === '..') {
				if (count($out) > 1) {
					array_pop($out);
				}
				if ($i === count($segments) - 1) {
					$out[] = '';
				}
			} else {
				$out[] = $segment;
			}
		}

		return implode('/', $out) . $rest;
	}
}
