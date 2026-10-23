<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\Invoice;
use Monokulo\Whmcs\OrderRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderRequestTest extends TestCase {
	private static function invoice(string $balance = '12.50', string $currency = 'eur', int $id = 1042, string $number = '1042', array $items = ['Web hosting', 'Domain', 'SSL']): Invoice {
		return new Invoice($id, $number, 'Unpaid', $balance, $currency, $items);
	}

	public function test_the_body_monokulo_gets(): void {
		$body = OrderRequest::body(self::invoice(), 0, 'Invoice #1042', ' + %d more');

		$this->assertSame([
			'amount'                 => '12.5',
			'currency'               => 'EUR',
			'merchant_order_id'      => '1042',
			'show_merchant_order_id' => true,
			'description'            => 'Invoice #1042: Web hosting + 2 more',
			'idempotency_key'        => 'whmcs:1042:12.5:EUR:0',
		], $body);
	}

	/** A custom invoice number (WHMCS's sequential numbering) is what the client knows the invoice by. */
	public function test_the_client_sees_the_invoice_number(): void {
		$body = OrderRequest::body(self::invoice(number: 'INV-2026-007'), 0, 'Invoice #INV-2026-007', ' + %d more');

		$this->assertSame('INV-2026-007', $body['merchant_order_id']);
		$this->assertStringStartsWith('whmcs:1042:', $body['idempotency_key']);
	}

	/** The same invoice and balance pressed again is the same key; a changed balance, or a new attempt after expiry, is not. */
	public function test_the_idempotency_key_follows_invoice_balance_and_attempt(): void {
		$key = static fn(int $id, string $balance, int $attempt): string => OrderRequest::body(self::invoice($balance, 'EUR', $id), $attempt, 'x', '%d')['idempotency_key'];

		$this->assertSame($key(7, '10.00', 0), $key(7, '10.00', 0));
		$this->assertNotSame($key(7, '10.00', 0), $key(7, '10.50', 0));
		$this->assertNotSame($key(7, '10.00', 0), $key(8, '10.00', 0));
		$this->assertNotSame($key(7, '10.00', 0), $key(7, '10.00', 1));
		$this->assertLessThanOrEqual(100, strlen($key(2147483647, '9999999999999999.99', 2147483647)));
	}

	public static function amounts(): array {
		return [
			'trailing zero trimmed'  => ['12.50', 'EUR', '12.5'],
			'two decimals kept'      => ['12.35', 'EUR', '12.35'],
			'whole'                  => ['1000.00', 'USD', '1000'],
			'no thousands separator' => ['1234567.10', 'GBP', '1234567.1'],
			'yen has none'           => ['1500.00', 'JPY', '1500'],
			'yen rounds'             => ['1500.50', 'jpy', '1501'],
			'three-decimal dinar'    => ['12.50', 'KWD', '12.5'],
			'XMR not padded to 12'   => ['0.25', 'XMR', '0.25'],
			'a few more cents'       => ['0.10', 'USD', '0.1'],
		];
	}

	/** In the currency's own decimals, trailing zeros trimmed, never padded: Monokulo refuses more decimals than a fiat currency has. */
	#[DataProvider('amounts')]
	public function test_amounts_are_in_the_currencys_decimals(string $balance, string $currency, string $expected): void {
		$this->assertSame($expected, OrderRequest::amount($balance, $currency));
		$this->assertMatchesRegularExpression('/\A[0-9]+(\.[0-9]{1,' . max(1, OrderRequest::decimals($currency)) . '})?\z/', OrderRequest::amount($balance, $currency));
	}

	public function test_the_currencys_decimals(): void {
		$this->assertSame([2, 0, 3, 12, 2], array_map(OrderRequest::decimals(...), ['EUR', 'JPY', 'KWD', 'xmr', 'ZZZ']));
	}

	public function test_one_item_has_no_more(): void {
		$this->assertSame('Invoice #5: Hosting', OrderRequest::description('Invoice #5', ['Hosting'], ' + %d more'));
		$this->assertSame('Invoice #5', OrderRequest::description('Invoice #5', [], ' + %d more'));
	}

	/** WHMCS's line items run over several lines (the service, then its dates). */
	public function test_the_description_is_one_line(): void {
		$this->assertSame('Invoice #5: Starter - example.com (01/10/2026 - 31/10/2026)', OrderRequest::description('Invoice #5', ["Starter - example.com\r\n(01/10/2026 - 31/10/2026)"], ' + %d more'));
	}

	public function test_a_long_description_is_cut_at_140_characters(): void {
		$description = OrderRequest::description('Invoice #5', [str_repeat('é', 200)], ' + %d more');

		$this->assertSame(140, mb_strlen($description, 'UTF-8'));
		$this->assertStringEndsWith('…', $description);
		$this->assertTrue(mb_check_encoding($description, 'UTF-8'));
	}

	public function test_only_an_unpaid_invoice_with_a_balance_is_payable(): void {
		$this->assertTrue(self::invoice()->payable());
		$this->assertFalse(self::invoice('0.00')->payable());
		$this->assertFalse((new Invoice(1, '1', 'Paid', '10.00', 'USD', []))->payable());
		$this->assertFalse((new Invoice(1, '1', 'Cancelled', '10.00', 'USD', []))->payable());
	}
}
