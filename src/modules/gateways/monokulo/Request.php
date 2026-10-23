<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * What the callback reads from one request. `origin` is where it came in
 * (its scheme and Host), which tells a client on the shop's onion address
 * from one on its web address.
 */
final class Request {
	/**
	 * @param array<string, mixed> $query
	 */
	public function __construct(
		public readonly string $method,
		public readonly array $query,
		public readonly string $body = '',
		public readonly string $signature = '',
		public readonly string $traceparent = '',
		public readonly string $origin = '',
	) {
	}

	public static function fromGlobals(): self {
		return new self(
			strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
			$_GET,
			(string)file_get_contents('php://input'),
			(string)($_SERVER['HTTP_X_MONOKULO_SIGNATURE'] ?? ''),
			(string)($_SERVER['HTTP_TRACEPARENT'] ?? ''),
			self::originFromGlobals(),
		);
	}

	/** Where this request came in: `https://billing.example`, `http://….onion`. */
	public static function originFromGlobals(): string {
		$host = (string)($_SERVER['HTTP_HOST'] ?? '');
		if ($host === '' || !preg_match('/\A[A-Za-z0-9.-]+(?::\d+)?\z/', $host)) {
			return '';
		}
		$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

		return ($https ? 'https' : 'http') . '://' . strtolower($host);
	}

	public function get(string $name): string {
		$value = $this->query[$name] ?? '';

		return is_string($value) ? $value : '';
	}
}
