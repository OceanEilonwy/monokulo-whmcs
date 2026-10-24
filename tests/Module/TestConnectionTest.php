<?php
namespace Monokulo\Whmcs\Tests\Module;

use Monokulo\Whmcs\Connection;
use Monokulo\Whmcs\Links;
use Monokulo\Whmcs\Repository;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\Tests\Support\Calls;
use Monokulo\Whmcs\Tests\Support\FakeMonokulo;
use Monokulo\Whmcs\Tests\Support\Vectors;
use Monokulo\Whmcs\Tests\Support\Whmcs;
use Monokulo\Whmcs\Tests\Support\WhmcsTestCase;
use WHMCS\Database\Capsule;

/**
 * The settings page's Test connection, through the callback against a
 * stand-in Monokulo: a signed-in admin's press has Monokulo send WHMCS's
 * webhook a test delivery with the entry's send-test-webhook form, and the
 * settings page says how it went; and the last test webhook received.
 */
final class TestConnectionTest extends WhmcsTestCase {
	use Calls;

	private const ADMIN = Whmcs::URL . 'admin/configgateways.php';

	protected function setUp(): void {
		parent::setUp();
		Whmcs::connect(self::$monokulo->url, Vectors::secret());
	}

	private function repository(): Repository {
		return new Repository(Capsule::connection());
	}

	/** @param array<string, string> $query the settings page's notes */
	private function notes(array $query = []): string {
		$_GET = $query;
		$_SERVER['SCRIPT_NAME'] = '/admin/configgateways.php';
		try {
			return monokulo_config()['UsageNotes']['Value'];
		} finally {
			$_GET = [];
			unset($_SERVER['SCRIPT_NAME']);
		}
	}

	/** @return array<string, string> the Test connection button's query, as the settings page has it */
	private function testLink(): array {
		$notes = $this->notes();
		$this->assertMatchesRegularExpression('#<a href="([^"]*action=test[^"]*)" class="btn btn-default btn-sm">Test connection</a>#', $notes);
		preg_match('#href="([^"]*action=test[^"]*)"#', $notes, $m);

		return self::query(html_entity_decode($m[1], ENT_QUOTES));
	}

	/** Presses Test connection, signed in: the settings page the admin is sent back to. */
	private function press(): string {
		$_SESSION['adminid'] = 1;
		$response = $this->get($this->testLink());
		$this->assertSame(303, $response->status, $response->body);
		$location = $response->headers['Location'];
		$this->assertStringStartsWith(self::ADMIN . '?monokulo_test=', $location);

		return $this->notes(self::query($location));
	}

	private function monokuloAnswers(int $status, array $body, array $headers = []): void {
		Api::serve(self::$monokulo);
		self::$monokulo->respond('POST', '/api/v1/webhook-tests', $status, $body, headers: ($status === 200 ? ['Content-Type' => Api::HAL_FORMS] : ['Content-Type' => Api::PROBLEM]) + $headers);
	}

	public function test_delivered_is_shown_with_its_status_and_time(): void {
		$this->monokuloAnswers(200, Api::webhookTest());

		$notes = $this->press();

		$this->assertStringStartsWith('<div class="alert alert-success" role="alert">Test webhook delivered: WHMCS answered HTTP 200 in 12 ms (event evt_0000000000000002).</div>', $notes);
		$sent = self::$monokulo->requests()[1];
		$this->assertSame(['POST', '/api/v1/webhook-tests', '{}', 'Bearer sk_test'], [$sent['method'], $sent['path'], $sent['body'], $sent['headers']['authorization']]);
		$this->assertStringContainsString('Test connection: test webhook evt_0000000000000002 delivered (HTTP 200 in 12 ms).', Whmcs::logs());
	}

	public function test_not_delivered_is_shown_with_the_error_and_the_next_try(): void {
		$this->monokuloAnswers(200, Api::webhookTest(false));

		$notes = $this->press();

		$this->assertStringStartsWith('<div class="alert alert-danger" role="alert">Test webhook not delivered: WHMCS answered HTTP 500: answered 500.', $notes);
		$this->assertStringContainsString('Monokulo tries it again at ' . date('Y-m-d H:i:s', 1760020452), $notes);
		$this->assertStringContainsString('not delivered: answered 500', Whmcs::logs());
	}

	public function test_rate_limited_is_shown_with_its_wait(): void {
		$this->monokuloAnswers(429, Api::WEBHOOK_TEST_PROBLEMS['rate-limited'], ['Retry-After' => '120']);

		$this->assertStringStartsWith('<div class="alert alert-warning" role="alert">Too many test webhooks for this store in the last minute. Wait 120 seconds, then test again.</div>', $this->press());
		$this->assertStringContainsString('HTTP 429 rate-limited', Whmcs::logs());
	}

	public function test_not_found_says_to_connect_again(): void {
		$this->monokuloAnswers(404, Api::WEBHOOK_TEST_PROBLEMS['not-found']);

		$this->assertStringContainsString('Monokulo has no webhook for this WHMCS. Connect again, then test again.', $this->press());
	}

	/** A kept entry whose form is refused (401, 404, 405) is read again first: still refused, then it's shown. */
	public function test_a_rejected_key_is_shown(): void {
		$this->monokuloAnswers(401, Api::WEBHOOK_TEST_PROBLEMS['unauthorized']);

		$this->assertStringContainsString('Monokulo refused the test (HTTP 401): This key was not accepted.', $this->press());
		$this->assertStringContainsString('connect again from the Monokulo gateway settings', Whmcs::logs());
	}

	public function test_an_unreachable_monokulo_is_shown(): void {
		Capsule::table('mod_monokulo_connection')->update(['api' => 'http://127.0.0.1:' . FakeMonokulo::freePort() . '/api/v1']);

		$this->assertStringContainsString('Your Monokulo could not be reached from this server.', $this->press());
	}

	public function test_a_monokulo_without_the_form_is_shown(): void {
		$entry = Api::entry();
		unset($entry['_templates']['send-test-webhook']);
		self::$monokulo->respond('GET', '/api/v1', 200, $entry, headers: Api::entryHeaders());

		$this->assertStringContainsString('Your Monokulo cannot send test webhooks yet', $this->press());
		$this->assertSame(['GET'], array_column(self::$monokulo->requests(), 'method'));
	}

	/** The link alone isn't enough: the browser must be signed in to the admin area. */
	public function test_testing_needs_a_signed_in_admin(): void {
		$this->monokuloAnswers(200, Api::webhookTest());

		$response = $this->get($this->testLink());

		$this->assertSame(403, $response->status);
		$this->assertStringContainsString('Sign in to the WHMCS admin area', $response->body);
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_a_link_the_settings_page_did_not_make_is_refused(): void {
		$_SESSION['adminid'] = 1;
		$this->monokuloAnswers(200, Api::webhookTest());
		$key = $this->repository()->linkKey();

		foreach ([
			'old'          => self::query(Links::test(Whmcs::URL, $key, self::ADMIN, time() - 3601)),
			'elsewhere'    => ['return' => 'https://evil.example/'] + $this->testLink(),
			'a connect'    => ['action' => 'test'] + self::query(Links::connect(Whmcs::URL, $key, self::ADMIN, time())),
		] as $why => $query) {
			$response = $this->get($query);
			$this->assertSame(400, $response->status, $why);
			$this->assertStringContainsString('This Test connection link has expired', $response->body, $why);
		}
		$this->assertSame([], self::$monokulo->requests());
	}

	public function test_not_connected_has_no_test_and_says_so_when_pressed(): void {
		Capsule::table('mod_monokulo_connection')->delete();
		$_SESSION['adminid'] = 1;

		$this->assertStringNotContainsString('action=test', $this->notes());
		$response = $this->get(self::query(Links::test(Whmcs::URL, $this->repository()->linkKey(), self::ADMIN, time())));
		$this->assertStringContainsString('Connect WHMCS to Monokulo first', $this->notes(self::query($response->headers['Location'])));
	}

	/** The test delivery arriving is what the settings page says, and connecting again starts over. */
	public function test_the_last_test_webhook_received_is_shown(): void {
		$this->assertStringContainsString('No test webhook received yet.', $this->notes());

		$delivery = Vectors::delivery('webhook.test');
		$this->assertSame(200, $this->post($delivery['body'], $delivery['headers']['X-Monokulo-Signature'], Vectors::sentAt())->status);

		$notes = $this->notes();
		$this->assertStringContainsString('Last test webhook received: ' . date('Y-m-d H:i:s', Vectors::sentAt()) . ' (evt_0000000000000012).', $notes);
		$this->assertStringNotContainsString('No test webhook received yet.', $notes);

		$this->repository()->saveConnection(new Connection(Whmcs::HOST, self::$monokulo->url . '/api/v1', 'pk_2', 'sk_2', 'whsec_2', 1760000001));
		$this->assertStringContainsString('No test webhook received yet.', $this->notes());
	}
}
