<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\Links;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase {
	private const KEY = 'k1';
	private const SYSTEM = 'https://billing.example/';

	/** @return array<string, string> */
	private static function query(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

		return $query;
	}

	public function test_a_pay_link_goes_to_the_callback_for_its_invoice(): void {
		$url = Links::pay(self::SYSTEM, self::KEY, 42);

		$this->assertStringStartsWith('https://billing.example/modules/gateways/callback/monokulo.php?action=pay&invoice=42&sig=', $url);
		$this->assertTrue(Links::payIsGenuine(self::KEY, 42, self::query($url)['sig']));
	}

	/** Another invoice's link, another install's key, no signature: none pays this invoice. */
	public function test_a_pay_link_is_good_for_its_own_invoice_only(): void {
		$sig = self::query(Links::pay(self::SYSTEM, self::KEY, 42))['sig'];

		$this->assertFalse(Links::payIsGenuine(self::KEY, 43, $sig));
		$this->assertFalse(Links::payIsGenuine('k2', 42, $sig));
		$this->assertFalse(Links::payIsGenuine(self::KEY, 42, ''));
		$this->assertFalse(Links::payIsGenuine(self::KEY, 0, self::query(Links::pay(self::SYSTEM, self::KEY, 0))['sig']));
	}

	public function test_a_connect_link_is_good_for_an_hour_with_its_own_return(): void {
		$return = 'https://billing.example/admin/configgateways.php';
		$query = self::query(Links::connect(self::SYSTEM, self::KEY, $return, 1000));

		$this->assertSame(['action' => 'connect', 'return' => $return, 't' => '1000'], array_diff_key($query, ['sig' => 1]));
		$this->assertTrue(Links::connectIsGenuine(self::KEY, $return, 1000, $query['sig'], 1000));
		$this->assertTrue(Links::connectIsGenuine(self::KEY, $return, 1000, $query['sig'], 1000 + 3600));
		$this->assertFalse(Links::connectIsGenuine(self::KEY, $return, 1000, $query['sig'], 1000 + 3601));
		$this->assertFalse(Links::connectIsGenuine(self::KEY, $return, 1000, $query['sig'], 999), 'made in the future');
		$this->assertFalse(Links::connectIsGenuine(self::KEY, 'https://evil.example/', 1000, $query['sig'], 1000), 'another return address');
		$this->assertFalse(Links::connectIsGenuine(self::KEY, $return, 1001, $query['sig'], 1001), 'another time');
	}

	public function test_a_query_is_added_to_any_url(): void {
		$this->assertSame('https://a.example/x.php?a=1&b=2', Links::with('https://a.example/x.php?a=1', 'b=2'));
		$this->assertSame('https://a.example/x.php?b=2', Links::with('https://a.example/x.php', 'b=2'));
	}
}
