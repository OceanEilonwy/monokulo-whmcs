<?php
namespace Monokulo\Whmcs;

final class HttpResponse {
	public function __construct(
		public readonly int $status,
		public readonly string $body,
	) {
	}
}
