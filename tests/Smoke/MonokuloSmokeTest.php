<?php
namespace Monokulo\Whmcs\Tests\Smoke;

use Monokulo\Whmcs\Tests\Support\Browser;
use Monokulo\Whmcs\Tests\Support\Page;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use PHPUnit\Framework\TestCase;

/**
 * The gateway, installed from its zip into the stand-in WHMCS, against a
 * real Monokulo (MONOKULO_URL, a fresh instance started with
 * docker/monokulo-smoke.toml), as a merchant and a client use it, over HTTP
 * with one browser:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. saves the Monokulo address in the gateway's settings and presses Connect;
 * 3. on Monokulo, sets up the store for WHMCS, picks the wallet, and goes
 *    back to WHMCS, which redeems the token: connected;
 * 4. a client presses Pay with Monero on an invoice: WHMCS creates the
 *    Monokulo order and sends them to its checkout, which loads and says
 *    pending.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 */
final class MonokuloSmokeTest extends TestCase {
	/** A mainnet watch-only wallet: a fixed view key and its public spend key, for tests only. */
	private const VIEW_KEY = '0707070707070707070707070707070707070707070707070707070707070707';
	private const SPEND_PUBLIC_KEY = '8621f587cfc4d6f869720476565ecd0972451ff7b8dada3498c9d3c2ca54fc90';
	private const PASSWORD = 'correct horse battery staple';

	private string $monokulo;
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->browser = new Browser();
		Whmcs::reset();
	}

	public function test_connect_then_a_client_is_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$this->connectWhmcs();
		$checkout = $this->payAnInvoice();

		// The Monokulo checkout loads, and the new order is waiting for payment.
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());
		$status = $this->browser->get($checkout . '/status');
		$this->assertSame(200, $status->status);
		$this->assertSame('pending', $status->json()['status']);
		$this->assertSame(0, $status->json()['confirmations']);
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/dashboard/signup');
			$done = $this->browser->submit($page, '/dashboard/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		}
		$this->assertContains($done->status, [302, 303], 'signed up: ' . $done->text());

		$form = $this->browser->get($this->monokulo . '/account/wallets/import?network=mainnet');
		$added = $this->browser->submit($form, '/account/wallets/import', [
			'name'             => 'Hosting takings',
			'view_key_hex'     => self::VIEW_KEY,
			'spend_pubkey_hex' => self::SPEND_PUBLIC_KEY,
			'network'          => 'mainnet',
		]);
		$this->assertSame(303, $added->status, 'wallet added: ' . $added->text());
	}

	private function connectWhmcs(): void {
		$settings = $this->browser->follow($this->browser->get(Whmcs::URL . 'admin/login.php'));
		$saved = $this->browser->submit($settings, 'configgateways.php', ['address' => $this->monokulo]);
		$this->assertStringContainsString('Changes Saved Successfully!', $saved->text());

		// Connect: off to Monokulo, signed in already, with no store for WHMCS yet: store setup.
		$toMonokulo = $this->browser->get($this->connectLink($saved));
		$this->assertStringStartsWith($this->monokulo . '/plugins/connect/whmcs?', (string)$toMonokulo->location(), $toMonokulo->status . $toMonokulo->headers . substr($toMonokulo->body, 0, 2000));

		$setup = $this->browser->follow($toMonokulo);
		$this->assertStringContainsString('WHMCS', $setup->text(), 'Monokulo names the plugin');
		$wallet = $this->browser->follow($this->browser->submit($setup, '/setup', ['store_name' => 'Smoke test hosting']));

		$done = $this->browser->follow($this->browser->submit($wallet, '/setup/wallet/existing'));
		$this->assertStringContainsString('Back to WHMCS', $done->text());

		// Back to WHMCS: Monokulo returns to the callback with the token, which
		// redeems it and sends the browser to the gateway's settings.
		$back = $this->browser->submit($done, '/plugins/connect/whmcs');
		$this->assertStringStartsWith(Whmcs::CALLBACK . '?action=return&', (string)$back->location());
		$return = $this->browser->get((string)$back->location());
		$this->assertStringContainsString('monokulo_connected=1', (string)$return->location(), $return->body);

		$admin = $this->browser->get((string)$return->location());
		$this->assertStringContainsString('WHMCS is connected', $admin->text());
		$connection = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertSame(Whmcs::HOST, $connection['host']);
		$this->assertSame($this->monokulo, rtrim($connection['endpoint'], '/'));
		$this->assertStringStartsWith('pk_', $connection['public_key']);
		$this->assertNotSame('', $connection['webhook_secret']);
	}

	/** A client's invoice, paid with Monero; returns the Monokulo checkout they were sent to. */
	private function payAnInvoice(): string {
		$invoice = Whmcs::invoice(['Starter hosting - example.com (01/11/2026 - 30/11/2026)' => '12.50', 'Domain renewal - example.com' => '9.99'], 'USD');

		$page = $this->browser->get(Whmcs::URL . 'viewinvoice.php?id=' . $invoice);
		$this->assertMatchesRegularExpression('#<a href="([^"]+)" class="btn btn-success">Pay with Monero</a>#', $page->body);
		preg_match('#<a href="([^"]+)" class="btn#', $page->body, $m);

		$pay = $this->browser->get(html_entity_decode($m[1], ENT_QUOTES));
		$this->assertSame(303, $pay->status, $pay->text());
		$connection = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertStringStartsWith($this->monokulo . '/pay/' . $connection['public_key'] . '/orders/', (string)$pay->location());

		$order = Whmcs::rows('mod_monokulo_order')[0];
		$this->assertSame([$invoice, '22.49', 'USD'], [$order['invoice_id'], $order['amount'], $order['currency']]);
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($invoice));

		return (string)$pay->location();
	}

	private function connectLink(Page $settings): string {
		$this->assertMatchesRegularExpression('#href="([^"]*action=connect[^"]*)"#', $settings->body);
		preg_match('#href="([^"]*action=connect[^"]*)"#', $settings->body, $m);

		return html_entity_decode($m[1], ENT_QUOTES);
	}
}
