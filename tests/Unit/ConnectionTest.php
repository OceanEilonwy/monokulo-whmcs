<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase {
	public function test_logs_go_to_the_entry_and_the_dashboard_is_on_its_host(): void {
		$c = new Connection('shop.example', 'https://pay.example.com/api/v1', 'pk_x', 'sk_x', 'whsec_x', 1);

		$this->assertSame('https://pay.example.com/api/v1/logs', $c->logsUrl());
		$this->assertSame('https://pay.example.com/stores/st%201/orders/o1', $c->dashboardOrderUrl('st 1', 'o1'));
	}
}
