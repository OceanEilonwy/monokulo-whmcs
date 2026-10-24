<?php
namespace Monokulo\Whmcs\Tests\Unit;

use Monokulo\Whmcs\ApiError;
use Monokulo\Whmcs\Tests\Support\Api;
use Monokulo\Whmcs\TestWebhook;
use PHPUnit\Framework\TestCase;

/**
 * Test connection's result, from the send-test-webhook form's recorded
 * answers (tests/Support/Api.php, checked against Monokulo's OpenAPI by
 * the smoke suite) to the notice on the settings page.
 */
final class TestWebhookTest extends TestCase {
	/** The settings page's notice for a query string, as the callback sends the admin back with it. */
	private static function notice(string $query): string {
		parse_str($query, $parsed);

		return TestWebhook::notice($parsed);
	}

	public function test_delivered_says_the_status_and_how_long_it_took(): void {
		$notice = self::notice(TestWebhook::fromAnswer(Api::webhookTest()));

		$this->assertSame('<div class="alert alert-success" role="alert">Test webhook delivered: WHMCS answered HTTP 200 in 12 ms (event evt_0000000000000002).</div>', $notice);
	}

	public function test_not_delivered_says_why_and_when_monokulo_tries_again(): void {
		$notice = self::notice(TestWebhook::fromAnswer(Api::webhookTest(false)));

		$this->assertStringStartsWith('<div class="alert alert-danger" role="alert">Test webhook not delivered: WHMCS answered HTTP 500: answered 500.', $notice);
		$this->assertStringContainsString('Monokulo tries it again at ' . date('Y-m-d H:i:s', 1760020452) . '.', $notice);
	}

	/** Nothing answered (timed out, refused): no status, Monokulo's error. */
	public function test_nothing_answering_says_so(): void {
		$answer = Api::webhookTest(false);
		unset($answer['status'], $answer['response']);
		$answer['error'] = 'could not connect: <refused>';

		$notice = self::notice(TestWebhook::fromAnswer($answer));

		$this->assertStringContainsString('Test webhook not delivered: nothing answered: could not connect: &lt;refused&gt;.', $notice);
	}

	/** Retried later is left out once Monokulo won't try again. */
	public function test_no_next_attempt_says_no_retry(): void {
		$answer = Api::webhookTest(false);
		unset($answer['next_attempt_at']);

		$this->assertStringNotContainsString('tries it again', self::notice(TestWebhook::fromAnswer($answer)));
	}

	public function test_rate_limited_says_how_long_to_wait(): void {
		$problem = Api::WEBHOOK_TEST_PROBLEMS['rate-limited'];
		$notice = self::notice(TestWebhook::fromProblem(ApiError::from(429, (string)json_encode($problem), '120')));

		$this->assertSame('<div class="alert alert-warning" role="alert">Too many test webhooks for this store in the last minute. Wait 120 seconds, then test again.</div>', $notice);
	}

	public function test_not_found_says_to_connect_again(): void {
		$problem = Api::WEBHOOK_TEST_PROBLEMS['not-found'];
		$notice = self::notice(TestWebhook::fromProblem(ApiError::from(404, (string)json_encode($problem))));

		$this->assertSame('<div class="alert alert-danger" role="alert">Monokulo has no webhook for this WHMCS. Connect again, then test again.</div>', $notice);
	}

	/** Any other problem: its status and detail. */
	public function test_another_problem_says_what_monokulo_said(): void {
		$problem = Api::WEBHOOK_TEST_PROBLEMS['unauthorized'];
		$notice = self::notice(TestWebhook::fromProblem(ApiError::from(401, (string)json_encode($problem))));

		$this->assertSame('<div class="alert alert-danger" role="alert">Monokulo refused the test (HTTP 401): This key was not accepted. Check it, or connect the store or app again.</div>', $notice);
	}

	public function test_the_other_outcomes_have_their_notice(): void {
		$this->assertStringContainsString('could not be reached', self::notice(TestWebhook::outcome('unreachable')));
		$this->assertStringContainsString('alert-warning', self::notice(TestWebhook::outcome('unoffered')));
		$this->assertStringContainsString('Connect WHMCS to Monokulo first', self::notice(TestWebhook::outcome('not-connected')));
	}

	/** A long error is cut short on the way back to the settings page. */
	public function test_a_long_error_is_cut_short(): void {
		$answer = Api::webhookTest(false);
		$answer['error'] = str_repeat('é', 1000);

		$this->assertStringContainsString(str_repeat('é', 300) . '….', self::notice(TestWebhook::fromAnswer($answer)));
	}

	/** Not back from a test, or a query nobody made: no notice. */
	public function test_no_or_an_unknown_test_shows_nothing(): void {
		$this->assertSame('', TestWebhook::notice([]));
		$this->assertSame('', TestWebhook::notice(['monokulo_test' => 'pwned']));
		$this->assertSame('', TestWebhook::notice(['monokulo_test' => ['delivered']]));
	}
}
