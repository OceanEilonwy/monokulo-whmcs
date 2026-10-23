<?php
namespace Monokulo\Whmcs\Tests\Smoke;

use Monokulo\Whmcs\Tests\Support\Api;
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
 *    Monokulo order through the API's create-order form and sends them to
 *    the order's checkout, which loads; the order, read with its `self`
 *    link, is pending.
 *
 * And the stand-in Monokulo's answers (tests/Support/Api.php) are checked
 * against the OpenAPI document and the API of the real one.
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
		$orderId = Whmcs::rows('mod_monokulo_order')[0]['monokulo_order_id'];
		$this->assertSame($this->monokulo . '/checkout/' . $orderId, $checkout);

		// The real API, read with the key WHMCS keeps: the entry's form is
		// where the stand-in's is, and the order has what WHMCS reads.
		$entry = $this->api('/api/v1');
		$this->assertSame(Api::entry()['_templates']['create-order']['target'], $entry['_templates']['create-order']['target']);
		$this->assertSame('POST', $entry['_templates']['create-order']['method']);
		$order = $this->api('/api/v1/orders/' . $orderId);
		$this->assertSame('pending', $order['status']);
		$this->assertSame('/checkout/' . $orderId, $order['_links']['mk:checkout']['href']);
		$this->assertSame([], array_diff(array_keys(Api::order()), array_keys($order)), 'the stand-in\'s order has no field the real one lacks');
		$this->assertSame([], array_diff(array_keys(Api::order()['_links']), array_keys($order['_links'])));
	}

	/** The stand-in's API is the real one's, as its OpenAPI document describes it. */
	public function test_the_stand_in_api_matches_the_openapi_document(): void {
		$doc = $this->browser->get($this->monokulo . '/api/v1/openapi.json');
		$this->assertSame(200, $doc->status);
		$openapi = $doc->json();
		$schemas = $openapi['components']['schemas'];

		$create = $openapi['paths']['/api/v1/orders']['post'];
		$this->assertSame('create-order', $create['x-hal-forms-template']);
		$this->assertContains('sk', $create['x-monokulo-keys']);
		$this->assertArrayHasKey('Location', $create['responses']['201']['headers']);
		$this->assertArrayHasKey(Api::HAL_FORMS, $create['responses']['201']['content']);
		$this->assertArrayHasKey(Api::PROBLEM, $create['responses']['default']['content']);

		// What WHMCS sends is what the form takes, and every required field.
		$input = $schemas['OrdersInput'];
		$sent = ['amount', 'currency', 'merchant_order_id', 'show_merchant_order_id', 'description', 'idempotency_key'];
		$this->assertSame([], array_diff($sent, array_keys($input['properties'])));
		$this->assertSame([], array_diff($input['required'], $sent));
		$fields = array_column(Api::entry()['_templates']['create-order']['properties'], 'name');
		sort($fields);
		$real = array_keys($input['properties']);
		sort($real);
		$this->assertSame($real, $fields, 'the stand-in\'s form has the real one\'s fields');

		// The order: its fields and links, as the stand-in answers them.
		$order = $schemas['Order'];
		$this->assertSame([], array_diff(array_keys(Api::order()), array_keys($order['properties'])));
		$this->assertSame([], array_diff(array_keys(Api::order()['_links']), array_keys($order['properties']['_links']['properties'])));
		$this->assertSame([], array_diff(array_keys(Api::entry()['_links']), array_keys($schemas['Root']['properties']['_links']['properties'])));
		$this->assertSame(['type', 'title', 'status', 'detail'], $schemas['Problem']['required']);
		$this->assertSame(['type', 'title', 'status', 'detail'], array_keys(Api::problem('x', 400, 't', 'd')));

		// Logs: where the entry's `logs` is, with the secret key.
		$logs = $openapi['paths']['/api/v1/logs']['post'];
		$this->assertContains('sk', $logs['x-monokulo-keys']);
		$this->assertSame(['entries'], $schemas['LogsInput']['required']);
	}

	/** GET a path of the real API with the store's secret key WHMCS keeps. @return array<string, mixed> */
	private function api(string $path): array {
		$secret = Whmcs::rows('mod_monokulo_connection')[0]['secret_token'];
		$curl = curl_init($this->monokulo . $path);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Accept: ' . Api::HAL_FORMS]]);
		$body = (string)curl_exec($curl);
		$this->assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);

		return json_decode($body, true);
	}

	/** A fresh Monokulo asks for its operator first; that account is the merchant's too. */
	private function signUpToMonokulo(): void {
		$home = $this->browser->get($this->monokulo . '/');
		$email = 'merchant-' . bin2hex(random_bytes(4)) . '@example.test';
		if (str_ends_with((string)$home->location(), '/admin/setup')) {
			$page = $this->browser->get($this->monokulo . '/admin/setup');
			$done = $this->browser->submit($page, '/admin/setup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
		} else {
			$page = $this->browser->get($this->monokulo . '/signup');
			$done = $this->browser->submit($page, '/signup', ['email' => $email, 'password' => self::PASSWORD, 'confirm_password' => self::PASSWORD]);
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
		$this->assertSame($this->monokulo . '/api/v1', $connection['api']);
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
		$this->assertStringStartsWith($this->monokulo . '/checkout/order_', (string)$pay->location());

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
