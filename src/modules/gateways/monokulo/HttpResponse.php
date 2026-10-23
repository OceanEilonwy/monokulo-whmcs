<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

final class HttpResponse {
	/**
	 * @param array<string, string> $headers by lowercase name
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = [],
	) {
	}
}
