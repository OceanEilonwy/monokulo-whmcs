<?php
namespace Monokulo\Whmcs\Tests\Support;

use Monokulo\Whmcs\Callback;
use Monokulo\Whmcs\Request;
use Monokulo\Whmcs\Response;
use Monokulo\Whmcs\Whmcs as Platform;

/** The callback file's work, in this process: what it does after init.php. */
trait Calls {
	/** @param array<string, string> $query */
	protected function get(array $query, ?int $now = null): Response {
		return $this->call(new Request('GET', $query), $now);
	}

	protected function post(string $body, string $signature, ?int $now = null, string $traceparent = ''): Response {
		return $this->call(new Request('POST', [], $body, $signature, $traceparent), $now);
	}

	protected function call(Request $request, ?int $now = null): Response {
		return (new Callback(new Platform(), getGatewayVariables('monokulo'), $now ?? time()))->handle($request);
	}

	/** Query parameters of `url`. @return array<string, string> */
	protected static function query(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

		return $query;
	}
}
