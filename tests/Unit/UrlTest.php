<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Monokulo's links resolved against the address the gateway called. */
final class UrlTest extends TestCase {
	public static function links(): array {
		return [
			'a path on the host'        => ['https://pay.example.com/api/v1', '/checkout/order_1', 'https://pay.example.com/checkout/order_1'],
			'keeps the port'            => ['http://127.0.0.1:8083/plugins/connect/whmcs/finish', '/api/v1', 'http://127.0.0.1:8083/api/v1'],
			'an onion stays an onion'   => ['http://abc.onion/api/v1', '/api/v1/orders', 'http://abc.onion/api/v1/orders'],
			'absolute is kept'          => ['https://pay.example.com/api/v1', 'https://other.example/x', 'https://other.example/x'],
			'scheme-relative'           => ['https://pay.example.com/api/v1', '//cdn.example/x', 'https://cdn.example/x'],
			'relative to the directory' => ['https://pay.example.com/api/v1', 'orders', 'https://pay.example.com/api/orders'],
			'dot segments'              => ['https://pay.example.com/api/v1/orders', '../logs', 'https://pay.example.com/api/logs'],
			'a query only'              => ['https://pay.example.com/api/v1/orders', '?offset=50', 'https://pay.example.com/api/v1/orders?offset=50'],
		];
	}

	#[DataProvider('links')]
	public function test_a_link_is_resolved(string $base, string $link, string $expected): void {
		$this->assertSame($expected, Url::resolve($base, $link));
	}
}
