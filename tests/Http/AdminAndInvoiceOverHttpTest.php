<?php
namespace Monokulo\Whmcs\Tests\Http;

use Monokulo\Whmcs\Tests\Support\Browser;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Tests\Support\Page;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use PHPUnit\Framework\TestCase;

/**
 * The gateway's pages over real HTTP, with one browser and a stand-in
 * Monokulo: an admin saves the address and connects (the connect flow's
 * redirects through the callback file), then a client presses Pay with
 * Monero on an invoice.
 */
final class AdminAndInvoiceOverHttpTest extends TestCase {
	private const SETTINGS = Whmcs::URL . 'admin/configgateways.php';

	private static FakeMonokulo $monokulo;
	private Browser $browser;

	public static function setUpBeforeClass(): void {
		self::$monokulo = new FakeMonokulo();
	}

	public static function tearDownAfterClass(): void {
		self::$monokulo->stop();
	}

	protected function setUp(): void {
		Whmcs::reset();
		self::$monokulo->reset();
		$this->browser = new Browser();
	}

	private function signIn(): Page {
		return $this->browser->follow($this->browser->get(Whmcs::URL . 'admin/login.php'));
	}

	private function connectLink(Page $settings): string {
		$this->assertMatchesRegularExpression('#href="([^"]*action=connect[^"]*)"#', $settings->body);
		preg_match('#href="([^"]*action=connect[^"]*)"#', $settings->body, $m);

		return html_entity_decode($m[1], ENT_QUOTES);
	}

	public function test_an_admin_saves_the_address_and_connects_over_http(): void {
		$settings = $this->signIn();
		$this->assertStringContainsString('Save your Monokulo address first', $settings->text());
		$this->assertStringContainsString('name="address" value="https://www.monokulo.com"', $settings->body);

		$saved = $this->browser->submit($settings, 'configgateways.php', ['address' => self::$monokulo->url . '/']);
		$this->assertSame(200, $saved->status, $saved->body);
		$this->assertStringContainsString('Changes Saved Successfully!', $saved->text());

		// Connect: off to Monokulo, with a nonce and the way back.
		$toMonokulo = $this->browser->get($this->connectLink($saved));
		$this->assertSame(303, $toMonokulo->status);
		$location = (string)$toMonokulo->location();
		$this->assertStringStartsWith(self::$monokulo->url . '/plugins/connect/whmcs?', $location);
		parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
		$this->assertSame(Whmcs::CALLBACK . '?action=return', $query['return_url']);

		// Monokulo sends the browser back with a token, which WHMCS redeems.
		self::$monokulo->respond('POST', '/plugins/connect/whmcs/finish', 200, ['public_key' => 'pk_http', 'secret_token' => 'sk_http', 'api' => '/api/v1', 'webhook_signing_secret' => 'whsec_http']);
		$back = $this->browser->get(Whmcs::CALLBACK . '?action=return&token=tok_1&nonce=' . $query['nonce']);
		$this->assertSame(self::SETTINGS . '?monokulo_connected=1', $back->location());

		$settings = $this->browser->get((string)$back->location());
		$this->assertStringContainsString('WHMCS is connected.', $settings->text());
		$this->assertStringContainsString('Connected as pk_http', $settings->text());
		$this->assertSame('pk_http', Whmcs::rows('mod_monokulo_connection')[0]['public_key']);
	}

	public function test_a_bad_address_is_not_saved_over_http(): void {
		$saved = $this->browser->submit($this->signIn(), 'configgateways.php', ['address' => 'pay.example.com']);

		$this->assertSame(422, $saved->status);
		$this->assertStringContainsString('http or https address', $saved->text());
		$this->assertSame([], array_filter(Whmcs::rows('tblpaymentgateways', ['setting' => 'address'])));
	}

	/** The Connect link copied to a browser not signed in to the admin area does nothing. */
	public function test_connect_needs_the_admin_session_over_http(): void {
		Whmcs::setting('address', self::$monokulo->url);
		$link = $this->connectLink($this->signIn());

		$page = (new Browser())->get($link);

		$this->assertSame(403, $page->status);
		$this->assertStringContainsString('Sign in to the WHMCS admin area', $page->text());
	}

	public function test_a_client_pays_an_invoice_over_http(): void {
		Whmcs::connect(self::$monokulo->url, 'whsec');
		$invoice = Whmcs::invoice(['Hosting' => '25.00'], 'EUR');
		\Monokulo\Whmcs\Tests\Support\Api::serve(self::$monokulo, 'order_http');

		$page = $this->browser->get(Whmcs::URL . 'viewinvoice.php?id=' . $invoice);
		$this->assertMatchesRegularExpression('#<a href="([^"]+)" class="btn btn-success">Pay with Monero</a>#', $page->body);
		preg_match('#<a href="([^"]+)" class="btn#', $page->body, $m);
		$pay = $this->browser->get(html_entity_decode($m[1], ENT_QUOTES));

		$this->assertSame(303, $pay->status);
		$this->assertSame(self::$monokulo->url . '/checkout/order_http', $pay->location());
		$this->assertSame('25', json_decode(self::$monokulo->requests()[1]['body'], true)['amount']);
	}

	/**
	 * The shop on its onion address (the request's Host): the invoice's
	 * button stays on it, and the client goes to the order's checkout on
	 * Monokulo's onion address.
	 */
	public function test_a_client_on_the_shops_onion_address_pays_over_tor_over_http(): void {
		Whmcs::connect(self::$monokulo->url, 'whsec');
		$invoice = Whmcs::invoice(['Hosting' => '25.00'], 'EUR');
		\Monokulo\Whmcs\Tests\Support\Api::serve(self::$monokulo, 'order_tor', onion: true);
		$onion = ['Host' => 'shopabcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuv.onion'];

		$page = $this->browser->get(Whmcs::URL . 'viewinvoice.php?id=' . $invoice, $onion);
		preg_match('#<a href="([^"]+)" class="btn#', $page->body, $m);
		$link = html_entity_decode($m[1], ENT_QUOTES);
		$this->assertStringStartsWith('http://' . $onion['Host'] . '/modules/gateways/callback/monokulo.php?action=pay&', $link);
		$pay = $this->browser->get(str_replace('http://' . $onion['Host'] . '/', Whmcs::URL, $link), $onion);

		$this->assertSame(303, $pay->status);
		$this->assertSame(\Monokulo\Whmcs\Tests\Support\Api::ONION . '/checkout/order_tor', $pay->location());
	}

	public function test_a_tampered_pay_link_is_refused_over_http(): void {
		Whmcs::connect(self::$monokulo->url, 'whsec');
		$invoice = Whmcs::invoice();

		$page = $this->browser->get(Whmcs::CALLBACK . '?action=pay&invoice=' . $invoice . '&sig=' . str_repeat('0', 64));

		$this->assertSame(403, $page->status);
		$this->assertStringContainsString('This payment link is not valid', $page->text());
		$this->assertSame([], self::$monokulo->requests());
	}
}
