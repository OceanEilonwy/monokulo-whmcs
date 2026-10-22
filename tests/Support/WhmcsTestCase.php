<?php
namespace Monokulo\Whmcs\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test of the gateway inside the stand-in WHMCS, in this process, with a
 * stand-in Monokulo over real HTTP: each test starts from a WHMCS with the
 * gateway activated, its address set to the stand-in Monokulo, and nothing
 * else.
 */
abstract class WhmcsTestCase extends TestCase {
	protected static FakeMonokulo $monokulo;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		Whmcs::reset();
		Whmcs::setting('address', self::$monokulo->url);
		self::$monokulo->reset();
	}
}
