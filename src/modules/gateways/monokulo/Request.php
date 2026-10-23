<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/** What the callback reads from one request. */
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
	) {
	}

	public static function fromGlobals(): self {
		return new self(
			strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
			$_GET,
			(string)file_get_contents('php://input'),
			(string)($_SERVER['HTTP_X_MONOKULO_SIGNATURE'] ?? ''),
			(string)($_SERVER['HTTP_TRACEPARENT'] ?? ''),
		);
	}

	public function get(string $name): string {
		$value = $this->query[$name] ?? '';

		return is_string($value) ? $value : '';
	}
}
