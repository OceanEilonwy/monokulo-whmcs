<?php
namespace Monokulo\Whmcs\Tests\Smoke;

use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\Browser;
use Monokulo\Whmcs\Tests\Support\Page;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The gateway, installed from its zip into the stand-in WHMCS, against a
 * real Monokulo (MONOKULO_URL, a fresh instance started with
 * docker/monokulo-smoke.toml, which has an onion address; scripts/smoke
 * runs it once on its own address and once under a path), as a merchant
 * and clients use it, over HTTP with one browser:
 *
 * 1. the merchant signs up to Monokulo and adds a watch-only wallet;
 * 2. saves the Monokulo address in the gateway's settings and presses Connect;
 * 3. on Monokulo, sets up the store for WHMCS, picks the wallet, and goes
 *    back to WHMCS, which redeems the token: connected, with the API's
 *    entry and its onion entry;
 * 4. a client presses Pay with Monero on an invoice: WHMCS reads the entry,
 *    creates the order with its create-order form, and sends the client to
 *    its checkout, which loads; the order's dashboard page opens;
 * 5. a client on the shop's onion address pays the next invoice: sent to
 *    the order's checkout on Monokulo's onion address, the entry not read
 *    again; once the kept entry has expired, it is asked for again;
 * 6. a client pays an invoice in EUR, for which Monokulo has no rate (the
 *    stand-in rate provider rate limits it): no order, and the client is
 *    asked to try again when Monokulo says.
 *
 * And the stand-in Monokulo's answers (tests/Support/Api.php) are checked
 * against the real one's API and its OpenAPI document, and, with
 * MONOKULO_OPENAPI_FILE, the examples recorded in the document written at
 * the same commit.
 *
 * Nothing is paid. Monokulo's pages are filled in by their fields' names.
 */
final class MonokuloSmokeTest extends TestCase {
	/** A mainnet watch-only wallet: a fixed view key and its public spend key, for tests only. */
	private const VIEW_KEY = '0707070707070707070707070707070707070707070707070707070707070707';
	private const SPEND_PUBLIC_KEY = '8621f587cfc4d6f869720476565ecd0972451ff7b8dada3498c9d3c2ca54fc90';
	private const PASSWORD = 'correct horse battery staple';
	/** The shop's own onion address, as a Tor client's request names it. */
	private const SHOP_ONION = 'shopabcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuv.onion';

	private string $monokulo;
	/** The path Monokulo is served under (`/monokulo`), or ''. */
	private string $prefix;
	private Browser $browser;

	protected function setUp(): void {
		$url = getenv('MONOKULO_URL');
		if (!$url) {
			$this->markTestSkipped('Set MONOKULO_URL to a fresh Monokulo (scripts/smoke starts one).');
		}
		$this->monokulo = rtrim($url, '/');
		$this->prefix = rtrim((string)parse_url($this->monokulo, PHP_URL_PATH), '/');
		$this->browser = new Browser();
		Whmcs::reset();
	}

	public function test_connect_then_clients_are_sent_to_pay(): void {
		$this->signUpToMonokulo();
		$this->connectWhmcs();

		// A client on the shop's web address: the order's checkout, which loads.
		$checkout = $this->payAnInvoice();
		$order = Whmcs::rows('mod_monokulo_order')[0];
		$orderId = $order['monokulo_order_id'];
		$this->assertSame($this->monokulo . '/checkout/' . $orderId, $checkout);
		$page = $this->browser->get($checkout);
		$this->assertSame(200, $page->status, $page->text());

		// The entry is kept, for Monokulo's max-age.
		$kept = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertNotSame('', (string)$kept['entry_etag']);
		$this->assertEqualsWithDelta(time() + 3600, (int)$kept['entry_expires_at'], 120);

		// A client on the shop's onion address: the next order's checkout on
		// Monokulo's onion address, and the kept entry was enough.
		$tor = $this->payAnInvoice(self::SHOP_ONION);
		$torOrder = Whmcs::rows('mod_monokulo_order')[1]['monokulo_order_id'];
		$this->assertSame(Api::ONION . '/checkout/' . $torOrder, $tor);
		$this->assertSame($kept['entry_expires_at'], Whmcs::rows('mod_monokulo_connection')[0]['entry_expires_at'], 'not read again');

		// Expired: asked for again with its ETag, and kept for another hour.
		Capsule::table('mod_monokulo_connection')->update(['entry_expires_at' => time() - 1]);
		$this->payAnInvoice();
		$renewed = Whmcs::rows('mod_monokulo_connection')[0];
		$this->assertSame($kept['entry_etag'], $renewed['entry_etag']);
		$this->assertGreaterThan(time() + 3000, (int)$renewed['entry_expires_at']);

		// No exchange rate for the invoice's currency right now (the rate
		// provider is rate limiting Monokulo): no order, the client asked
		// to try again when Monokulo says, the merchant told why.
		$eur = Whmcs::invoice(['Starter hosting' => '20.00'], 'EUR');
		$page = $this->browser->get(Whmcs::URL . 'viewinvoice.php?id=' . $eur);
		preg_match('#<a href="([^"]+)" class="btn#', $page->body, $m);
		$refused = $this->browser->get(html_entity_decode($m[1], ENT_QUOTES));
		$this->assertSame(503, $refused->status, $refused->text());
		$this->assertStringContainsString('Monero prices are not available right now', $refused->text());
		$this->assertMatchesRegularExpression('/^Retry-After: 30\r?$/mi', $refused->headers);
		$this->assertSame([], Whmcs::rows('mod_monokulo_order', ['invoice_id' => $eur]));
		$this->assertStringContainsString('https://docs.monokulo.com/problems/rates-unavailable', Whmcs::logs());
		$this->assertStringContainsString('Monokulo suggests waiting 30 seconds', Whmcs::logs());

		// The real API, read with the key WHMCS keeps: the entry and the
		// order are what the stand-in answers.
		[$entry, $headers] = $this->api('/api/v1');
		$this->assertSame('private, max-age=3600', $headers['cache-control']);
		$this->assertContains('Authorization', array_map('trim', explode(',', $headers['vary'])));
		$this->assertSame($kept['entry_etag'], $headers['etag']);
		$standIn = Api::entry($this->prefix, onion: true);
		$this->assertEquals(array_keys($standIn['_templates']), array_keys($entry['_templates']), 'the forms a secret key is offered');
		foreach ($standIn['_templates'] as $name => $form) {
			$this->assertSame([$form['method'], $form['target']], [$entry['_templates'][$name]['method'], $entry['_templates'][$name]['target']], $name);
		}
		$this->assertEqualsCanonicalizing(array_keys($standIn['_links']), array_keys($entry['_links']));
		$this->assertSame(Api::ONION . '/api/v1', $entry['_links']['mk:onion-api']['href']);
		[$read] = $this->api('/api/v1/orders/' . $orderId);
		$this->assertSame('pending', $read['status']);
		$standInOrder = Api::order($orderId, $this->prefix, onion: true);
		$this->assertSame([], array_diff(array_keys($standInOrder), array_keys($read)), 'the stand-in\'s order has no field the real one lacks');
		$this->assertEqualsCanonicalizing(array_keys($standInOrder['_links']), array_keys($read['_links']));
		foreach (['self', 'mk:checkout', 'mk:onion-checkout'] as $rel) {
			$this->assertSame($standInOrder['_links'][$rel]['href'], $read['_links'][$rel]['href'], $rel);
		}
		$this->assertStringStartsWith($this->prefix . '/stores/', $read['_links']['mk:dashboard-order']['href']);

		// The order's dashboard page, as Monokulo links it (and its webhooks
		// do, absolute): the merchant's, signed in in this browser.
		$dashboard = $this->browser->get($this->monokulo . substr($read['_links']['mk:dashboard-order']['href'], strlen($this->prefix)));
		$this->assertSame(200, $dashboard->status);
		$this->assertStringContainsString($orderId, $dashboard->body);

		// A key Monokulo doesn't know is refused at the entry, as a problem.
		[$refused, $refusedHeaders, $status] = $this->api('/api/v1', 'sk_unknown');
		$this->assertSame(401, $status);
		$this->assertStringStartsWith(Api::PROBLEM, $refusedHeaders['content-type']);
		$this->assertSame('https://docs.monokulo.com/problems/unauthorized', $refused['type']);
	}

	/** The handshake's refusals are problems, which the gateway reads (its name, its detail). */
	public function test_a_spent_connect_token_is_a_problem(): void {
		$curl = curl_init($this->monokulo . '/plugins/connect/whmcs/finish');
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"token":"nope","webhook_url":"http://localhost:8091/x","name":"WHMCS"}', CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
		$body = json_decode((string)curl_exec($curl), true);

		$this->assertSame(401, curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
		$this->assertStringStartsWith(Api::PROBLEM, (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
		$error = \Monokulo\Whmcs\ApiError::from(401, (string)json_encode($body));
		$this->assertSame('invalid-connect-code', $error->problem);
		$this->assertNotSame('', $error->detail);
	}

	/** The stand-in's API is the real one's, as its OpenAPI document describes it. */
	public function test_the_stand_in_api_matches_the_openapi_document(): void {
		$doc = $this->browser->get($this->monokulo . '/api/v1/openapi.json');
		$this->assertSame(200, $doc->status);
		$this->checkDocument($doc->json());

		$file = getenv('MONOKULO_OPENAPI_FILE');
		if (!$file) {
			$this->markTestIncomplete('Set MONOKULO_OPENAPI (scripts/smoke) to the document written at the pinned commit, to check its examples too.');
		}
		$this->checkExamples(json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
	}

	/** @param array<string, mixed> $openapi */
	private function checkDocument(array $openapi): void {
		$schemas = $openapi['components']['schemas'];

		// The entry: kept per key, revalidated with its ETag.
		$root = $openapi['paths']['/api/v1']['get'];
		$this->assertSame(['Cache-Control', 'ETag', 'Vary'], array_values(array_intersect(['Cache-Control', 'ETag', 'Vary'], array_keys($root['responses']['200']['headers']))));
		$this->assertArrayHasKey('304', $root['responses']);
		$this->assertContains('If-None-Match', array_column($root['parameters'], 'name'));
		$this->assertSame([], array_diff(array_keys(Api::entry(onion: true)['_links']), array_keys($schemas['Root']['properties']['_links']['properties'])));

		// Its forms: what WHMCS sends is what they take.
		$create = $openapi['paths']['/api/v1/orders']['post'];
		$this->assertSame('create-order', $create['x-hal-forms-template']);
		$this->assertContains('sk', $create['x-monokulo-keys']);
		$this->assertArrayHasKey('Location', $create['responses']['201']['headers']);
		$this->assertArrayHasKey(Api::HAL_FORMS, $create['responses']['201']['content']);
		// Every problem it may answer, one response per status, each with
		// its example: the ones WHMCS handles are the stand-in's.
		$this->assertArrayNotHasKey('default', $create['responses']);
		foreach (Api::ORDER_PROBLEMS as $name => $problem) {
			$this->assertArrayHasKey(Api::PROBLEM, $create['responses'][(string)$problem['status']]['content'], $name);
		}
		foreach (['429', '503'] as $status) {
			$this->assertArrayHasKey('Retry-After', $create['responses'][$status]['headers'], $status);
		}
		$input = $schemas['OrdersInput'];
		$sent = ['amount', 'currency', 'merchant_order_id', 'show_merchant_order_id', 'description', 'idempotency_key'];
		$this->assertSame([], array_diff($sent, array_keys($input['properties'])));
		$this->assertSame([], array_diff($input['required'], $sent));
		$fields = array_column(Api::entry()['_templates']['create-order']['properties'], 'name');
		$this->assertEqualsCanonicalizing(array_keys($input['properties']), $fields, 'the stand-in\'s form has the real one\'s fields');
		$logs = $openapi['paths']['/api/v1/logs']['post'];
		$this->assertSame(['send-logs', ['sk']], [$logs['x-hal-forms-template'], $logs['x-monokulo-keys']]);
		$this->assertSame(['entries'], $schemas['LogsInput']['required']);
		$this->assertSame(['entries'], array_column(Api::entry()['_templates']['send-logs']['properties'], 'name'));

		// The order: its fields and links, and the pages they lead to.
		$order = $schemas['Order'];
		$this->assertSame([], array_diff(array_keys(Api::order()), array_keys($order['properties'])));
		$this->assertSame([], array_diff(array_keys(Api::order(onion: true)['_links']), array_keys($order['properties']['_links']['properties'])));
		// A field with no value is left out, never null.
		foreach (Api::OPTIONAL_ORDER_FIELDS as $field) {
			$this->assertNotContains($field, $order['required'] ?? [], $field);
			$this->assertStringNotContainsString('null', (string)json_encode($order['properties'][$field]), $field);
		}
		foreach (['/checkout/{order_id}', '/stores/{id}/orders/{order_id}'] as $page) {
			$this->assertArrayHasKey('text/html', $openapi['paths'][$page]['get']['responses']['200']['content'], $page);
		}
		$this->assertSame(['type', 'title', 'status', 'detail'], $schemas['Problem']['required']);
		$this->assertSame(['type', 'title', 'status', 'detail'], array_keys(Api::problem('x', 400, 't', 'd')));

		// The connect flow's handshake: what it hands over, and its problems.
		$finish = $openapi['paths']['/plugins/connect/{slug}/finish']['post'];
		$this->assertSame([], array_diff(['token', 'webhook_url', 'name'], array_keys($schemas['PluginConnectFinish']['properties'])));
		$this->assertSame([], array_diff(['public_key', 'secret_token', 'api', 'onion_api', 'webhook_signing_secret'], array_keys($schemas['PluginConnection']['properties'])));
		foreach (['400' => 'invalid-request', '401' => 'invalid-connect-code', '429' => 'rate-limited', '500' => 'internal', '503' => 'no-public-address'] as $status => $problem) {
			$this->assertArrayHasKey(Api::PROBLEM, $finish['responses'][$status]['content'], (string)$status);
			$this->assertStringContainsString('problems/' . $problem, $finish['responses'][$status]['description']);
		}
		// A server is never asked to solve a proof of work: not at the
		// handshake or the logs form (secret key only). The entry and
		// create-order list the challenge for a caller without a key or with
		// the public key (a browser); WHMCS always sends the secret key.
		foreach ([$finish, $logs] as $operation) {
			$this->assertStringNotContainsString('challenge-required', (string)json_encode($operation));
		}
		foreach ([$root, $create] as $operation) {
			$this->assertContains('pk', $operation['x-monokulo-keys']);
		}
	}

	/**
	 * The examples recorded against a test engine, by name (`sk`, and
	 * `sk-with-onion` from a Monokulo with an onion address): the entry as
	 * the secret key sees it, the order made with that key (the same order
	 * read back), and the plugin handshake's answer (`no-key`,
	 * `no-key-with-onion`). Each is what the stand-in answers, link for link.
	 *
	 * @param array<string, mixed> $openapi
	 */
	private function checkExamples(array $openapi): void {
		$entries = $this->examples($openapi['paths']['/api/v1']['get']['responses']['200']['content'][Api::HAL_FORMS]);
		$orders = $this->examples($openapi['paths']['/api/v1/orders']['post']['responses']['201']['content'][Api::HAL_FORMS]);
		$reads = $this->examples($openapi['paths']['/api/v1/orders/{order_id}']['get']['responses']['200']['content'][Api::HAL_FORMS]);
		$connections = $this->examples($openapi['paths']['/plugins/connect/{slug}/finish']['post']['responses']['200']['content']['application/json']);

		foreach (['sk' => false, 'sk-with-onion' => true] as $name => $onion) {
			$this->assertArrayHasKey($name, $entries);
			$example = $entries[$name];
			$standIn = Api::entry(onion: $onion);
			$this->assertSame($this->hrefs($standIn['_links']), $this->hrefs($example['_links']), $name);
			$this->assertEqualsCanonicalizing(array_keys($standIn['_templates']), array_keys($example['_templates']), $name);
			foreach ($standIn['_templates'] as $form => $template) {
				$this->assertSame([$template['method'], $template['target']], [$example['_templates'][$form]['method'], $example['_templates'][$form]['target']], $name . ' ' . $form);
				$this->assertEqualsCanonicalizing(array_column($template['properties'], 'name'), array_column($example['_templates'][$form]['properties'], 'name'), $name . ' ' . $form);
			}

			$this->assertArrayHasKey($name, $orders);
			$order = $orders[$name];
			$standIn = Api::order($order['order_id'], onion: $onion);
			$this->assertSame([], array_diff(array_keys($standIn), array_keys($order)), $name);
			$dashboard = $order['_links']['mk:dashboard-order']['href'];
			$this->assertMatchesRegularExpression('#\A/stores/[^/]+/orders/' . preg_quote($order['order_id'], '#') . '\z#', $dashboard, $name);
			$standIn['_links']['mk:dashboard-order']['href'] = $dashboard;
			$this->assertSame($this->hrefs($standIn['_links']), $this->hrefs($order['_links']), $name);
			$this->assertSame($order['order_id'], $reads[$name]['order_id'], 'the order made is the one read: ' . $name);
			$this->assertSame($this->hrefs($order['_links']), $this->hrefs($reads[$name]['_links']), $name);
		}

		// The problems the stand-in answers are the recorded ones, as they are.
		foreach (['/api/v1/orders' => Api::ORDER_PROBLEMS, '/plugins/connect/{slug}/finish' => Api::FINISH_PROBLEMS] as $path => $problems) {
			foreach ($problems as $name => $problem) {
				$recorded = $this->examples($openapi['paths'][$path]['post']['responses'][(string)$problem['status']]['content'][Api::PROBLEM]);
				$this->assertArrayHasKey($name, $recorded, $path);
				$this->assertEquals($recorded[$name], $problem, $path . ' ' . $name);
			}
		}

		$this->assertSame('/api/v1', $connections['no-key']['api']);
		$this->assertArrayNotHasKey('onion_api', $connections['no-key']);
		$this->assertSame(Api::ONION . '/api/v1', $connections['no-key-with-onion']['onion_api']);
		foreach ($connections as $name => $example) {
			$this->assertSame([], array_diff(['public_key', 'secret_token', 'api', 'webhook_signing_secret'], array_keys($example)), $name);
			$this->assertStringStartsWith('pk_', $example['public_key']);
			$this->assertStringStartsWith('sk_', $example['secret_token']);
		}
	}

	/** @param array<string, mixed> $content one media type's content @return array<string, array<string, mixed>> its examples' values, by name */
	private function examples(array $content): array {
		$out = [];
		foreach ($content['examples'] ?? [] as $name => $example) {
			$out[$name] = $example['value'];
		}

		return $out;
	}

	/** @param array<string, mixed> $links @return array<string, string> each link's href, by relation (curies aside) */
	private function hrefs(array $links): array {
		unset($links['curies']);
		$out = array_map(static fn(array $link): string => $link['href'], $links);
		ksort($out);

		return $out;
	}

	/**
	 * GET a path of the real API with a key (the store's secret key WHMCS
	 * keeps, unless given): its JSON, its headers (lowercase names) and status.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, string>, 2: int}
	 */
	private function api(string $path, ?string $key = null): array {
		$key ??= Whmcs::rows('mod_monokulo_connection')[0]['secret_token'];
		$headers = [];
		$curl = curl_init($this->monokulo . $path);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Accept: ' . Api::HAL_FORMS],
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$name = strtolower(trim($parts[0]));
					// A header sent more than once (Vary) is one list.
					$headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($parts[1]) : trim($parts[1]);
				}

				return strlen($line);
			},
		]);
		$body = (string)curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		if ($key !== 'sk_unknown') {
			$this->assertSame(200, $status, $body);
		}

		return [json_decode($body, true), $headers, $status];
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
		$this->assertSame(Api::ONION . '/api/v1', $connection['onion_api'], 'the handshake handed over the onion entry');
		$this->assertStringStartsWith('pk_', $connection['public_key']);
		$this->assertNotSame('', $connection['webhook_secret']);
	}

	/**
	 * A client's invoice, paid with Monero, on the shop's web address or
	 * (`host`) another, such as its onion address; returns where the client
	 * was sent.
	 */
	private function payAnInvoice(?string $host = null): string {
		$invoice = Whmcs::invoice(['Starter hosting - example.com (01/11/2026 - 30/11/2026)' => '12.50', 'Domain renewal - example.com' => '9.99'], 'USD');
		$headers = $host !== null ? ['Host' => $host] : [];

		$page = $this->browser->get(Whmcs::URL . 'viewinvoice.php?id=' . $invoice, $headers);
		$this->assertMatchesRegularExpression('#<a href="([^"]+)" class="btn btn-success">Pay with Monero</a>#', $page->body);
		preg_match('#<a href="([^"]+)" class="btn#', $page->body, $m);
		$link = html_entity_decode($m[1], ENT_QUOTES);
		$this->assertStringStartsWith('http://' . ($host ?? Whmcs::HOST) . '/', $link, 'the button is on the address the client is on');

		// Over Tor the request reaches the same server, naming the onion host.
		$pay = $this->browser->get(Whmcs::URL . substr($link, strlen('http://' . ($host ?? Whmcs::HOST) . '/')), $headers);
		$this->assertSame(303, $pay->status, $pay->text());

		$order = Whmcs::rows('mod_monokulo_order', ['invoice_id' => $invoice])[0];
		$this->assertSame(['22.49', 'USD'], [$order['amount'], $order['currency']]);
		$this->assertSame('Unpaid', Whmcs::invoiceStatus($invoice));

		return (string)$pay->location();
	}

	private function connectLink(Page $settings): string {
		$this->assertMatchesRegularExpression('#href="([^"]*action=connect[^"]*)"#', $settings->body);
		preg_match('#href="([^"]*action=connect[^"]*)"#', $settings->body, $m);

		return html_entity_decode($m[1], ENT_QUOTES);
	}
}
