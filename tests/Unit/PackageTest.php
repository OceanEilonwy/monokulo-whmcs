<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\Plugin;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase {
	public function test_the_version_is_the_same_everywhere(): void {
		$this->assertSame(trim((string)file_get_contents(__DIR__ . '/../../VERSION')), Plugin::VERSION);
		$this->assertSame('whmcs/' . Plugin::VERSION, Plugin::client());
	}

	/** Monokulo takes a slug of a lowercase letter then 1 to 31 lowercase letters, digits or hyphens, and a name up to 40. */
	public function test_the_slug_and_name_are_ones_monokulo_takes(): void {
		$this->assertMatchesRegularExpression('/\A[a-z][a-z0-9-]{1,31}\z/', Plugin::SLUG);
		$this->assertLessThanOrEqual(40, mb_strlen(Plugin::NAME));
	}

	/** WHMCS finds a gateway by its file's name, and calls its functions by that name. */
	public function test_the_module_files_are_where_whmcs_looks(): void {
		$src = __DIR__ . '/../../src/modules/gateways/';

		$this->assertFileExists($src . Plugin::MODULE . '.php');
		$this->assertFileExists($src . 'callback/' . Plugin::MODULE . '.php');
		$module = (string)file_get_contents($src . Plugin::MODULE . '.php');
		foreach (['MetaData', 'config', 'config_validate', 'link'] as $function) {
			$this->assertStringContainsString('function ' . Plugin::MODULE . '_' . $function . '(', $module);
		}
		$this->assertStringNotContainsString('function ' . Plugin::MODULE . '_refund(', $module, 'Monokulo has no refunds');
	}

	/** Loaded by WHMCS only: opened directly, the module file says so and does nothing. */
	public function test_the_module_file_refuses_a_direct_request(): void {
		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../src/modules/gateways/monokulo.php'));

		$this->assertSame('This file cannot be accessed directly', $output);
	}
}
