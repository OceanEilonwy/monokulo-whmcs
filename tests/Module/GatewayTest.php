<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Links;
use Monokulo\Whmcs\Plugin;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Calls;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use Monokulo\Whmcs\Tests\Support\WhmcsTestCase;
use WHMCS\Database\Capsule;
use WHMCS\Exception\Module\InvalidConfiguration;

/**
 * The gateway module's functions, as WHMCS calls them: its metadata, its
 * settings page with the connection and the Connect button, the check on
 * saving, and the Pay with Monero button on an invoice.
 */
final class GatewayTest extends WhmcsTestCase {
	use Calls;

	private const ADMIN_PAGE = '/admin/configgateways.php';

	/** @param array<string, string> $query */
	private function notes(array $query = [], string $script = self::ADMIN_PAGE): string {
		$_GET = $query;
		$_SERVER['SCRIPT_NAME'] = $script;
		try {
			return monokulo_config()['UsageNotes']['Value'];
		} finally {
			$_GET = [];
			unset($_SERVER['SCRIPT_NAME']);
		}
	}

	private function connectLink(string $notes): string {
		$this->assertMatchesRegularExpression('#href="([^"]*action=connect[^"]*)"#', $notes);
		preg_match('#href="([^"]*action=connect[^"]*)"#', $notes, $m);

		return html_entity_decode($m[1], ENT_QUOTES);
	}

	public function test_whmcs_lists_it_as_a_third_party_gateway_with_no_card_form(): void {
		$this->assertSame(['DisplayName' => 'Monokulo (Monero)', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => false], monokulo_MetaData());
	}

	public function test_the_settings_are_the_address_error_forwarding_and_the_connection(): void {
		$config = monokulo_config();

		$this->assertSame(['FriendlyName', 'address', 'forwardErrors', 'UsageNotes'], array_keys($config));
		$this->assertSame(['Type' => 'System', 'Value' => 'Monokulo (Monero)'], $config['FriendlyName']);
		$this->assertSame('text', $config['address']['Type']);
		$this->assertSame('yesno', $config['forwardErrors']['Type']);
		$this->assertSame('System', $config['UsageNotes']['Type']);
	}

	/** Opening the settings makes the gateway's tables, as nothing else does on activation. */
	public function test_opening_the_settings_makes_the_tables(): void {
		Capsule::schema()->dropIfExists('mod_monokulo_order');

		monokulo_config();

		$this->assertTrue(Capsule::schema()->hasTable('mod_monokulo_order'));
	}

	public function test_without_an_address_there_is_nothing_to_connect_to_yet(): void {
		Whmcs::setting('address', '');

		$notes = $this->notes();

		$this->assertStringContainsString('Not connected', $notes);
		$this->assertStringContainsString('Save your Monokulo address first', $notes);
		$this->assertStringNotContainsString('action=connect', $notes);
	}

	/** Connect: to the callback, back to this page afterwards, signed, for an hour. */
	public function test_with_an_address_connect_is_offered(): void {
		$link = $this->connectLink($this->notes());

		$this->assertStringStartsWith(Whmcs::CALLBACK . '?action=connect&', $link);
		$query = self::query($link);
		$this->assertSame(Whmcs::URL . 'admin/configgateways.php', $query['return']);
		$key = (new Repository(Capsule::connection()))->linkKey();
		$this->assertTrue(Links::connectIsGenuine($key, $query['return'], (int)$query['t'], $query['sig'], time()));
		$this->assertStringContainsString('>Connect<', $this->notes());
		$this->assertStringContainsString('Monokulo sends payment updates to ' . Whmcs::CALLBACK, $this->notes());
	}

	/** WHMCS lets the admin folder be renamed: connecting comes back to the folder the settings were opened in. */
	public function test_connect_comes_back_to_a_renamed_admin_folder(): void {
		$this->assertSame(Whmcs::URL . 'backoffice/configgateways.php', self::query($this->connectLink($this->notes([], '/backoffice/configgateways.php')))['return']);
		$this->assertSame(Whmcs::URL . 'admin/configgateways.php', self::query($this->connectLink($this->notes([], '')))['return']);
	}

	public function test_connected_shows_the_key_and_offers_connecting_again(): void {
		Whmcs::connect('https://pay.example.com', 'whsec');

		$notes = $this->notes();

		$this->assertStringContainsString('Connected as pk_test since 2025-10-09', $notes);
		$this->assertStringContainsString('>Connect again<', $notes);
	}

	/** Another System URL is another Monokulo store: not connected until connected from there. */
	public function test_a_connection_for_another_host_does_not_count(): void {
		Whmcs::connect('https://pay.example.com', 'whsec', host: 'old.example');

		$this->assertStringContainsString('Not connected', $this->notes());
	}

	public function test_an_older_connection_must_be_made_again(): void {
		Whmcs::connect('https://pay.example.com', 'whsec', '0');

		$this->assertStringContainsString('connected by an older version of this gateway', $this->notes());
	}

	public static function connectResults(): array {
		return [
			'connected'   => [['monokulo_connected' => '1'], 'WHMCS is connected. Clients can now pay invoices with Monero.'],
			'address'     => [['monokulo_connect_error' => 'address'], 'Save your Monokulo address before connecting.'],
			'host'        => [['monokulo_connect_error' => 'host'], 'came back to a different address'],
			'finish'      => [['monokulo_connect_error' => 'finish'], 'did not hand over the store\'s keys'],
			'unavailable' => [['monokulo_connect_error' => 'unavailable'], 'its operator has not set its public address'],
			'unreachable' => [['monokulo_connect_error' => 'unreachable'], 'could not be reached from this server'],
		];
	}

	/** @param array<string, string> $query */
	#[\PHPUnit\Framework\Attributes\DataProvider('connectResults')]
	public function test_the_settings_page_says_how_connecting_went(array $query, string $message): void {
		$this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), $this->notes($query));
	}

	public function test_an_unknown_connect_error_says_nothing(): void {
		$this->assertSame($this->notes(), $this->notes(['monokulo_connect_error' => '<script>']));
	}

	public static function addresses(): array {
		return [
			'https'      => ['https://pay.example.com', true],
			'localhost'  => ['http://localhost:8083/', true],
			'no scheme'  => ['pay.example.com', false],
			'empty'      => ['', false],
			'javascript' => ['javascript:alert(1)', false],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('addresses')]
	public function test_saving_takes_only_an_http_address(string $address, bool $saved): void {
		try {
			monokulo_config_validate(['address' => $address, 'forwardErrors' => '']);
			$this->assertTrue($saved, 'accepted');
		} catch (InvalidConfiguration $e) {
			$this->assertFalse($saved, 'refused');
			$this->assertStringContainsString('http or https address', $e->getMessage());
		}
	}

	/** Not connected: no button, a word for the client instead. */
	public function test_an_invoice_says_monero_is_not_set_up_until_connected(): void {
		$html = monokulo_link(['invoiceid' => Whmcs::invoice()] + getGatewayVariables('monokulo'));

		$this->assertStringContainsString('Monero payments are not set up yet', $html);
		$this->assertStringNotContainsString('action=pay', $html);
	}

	public function test_an_invoice_has_a_pay_with_monero_button_for_itself(): void {
		Whmcs::connect('https://pay.example.com', 'whsec');
		$invoice = Whmcs::invoice();

		$html = monokulo_link(['invoiceid' => $invoice] + getGatewayVariables('monokulo'));

		$this->assertMatchesRegularExpression('#<a href="([^"]+)" class="btn btn-success">Pay with Monero</a>#', $html);
		preg_match('#<a href="([^"]+)"#', $html, $m);
		$url = html_entity_decode($m[1], ENT_QUOTES);
		$this->assertStringStartsWith(Whmcs::CALLBACK . '?action=pay&invoice=' . $invoice . '&sig=', $url);
		$this->assertTrue(Links::payIsGenuine((new Repository(Capsule::connection()))->linkKey(), $invoice, self::query($url)['sig']));
		$this->assertStringContainsString('&amp;sig=', $html, 'escaped for HTML');
		$this->assertSame([], self::$monokulo->requests(), 'showing the button creates no Monokulo order');
	}

	/** One key per install, kept: links in invoice emails sent before still work. */
	public function test_the_link_key_is_made_once(): void {
		$repository = new Repository(Capsule::connection());
		$repository->install();

		$this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $repository->linkKey());
		$this->assertSame($repository->linkKey(), $repository->linkKey());
		$this->assertCount(1, Whmcs::rows('mod_monokulo_setting'));
	}

}
