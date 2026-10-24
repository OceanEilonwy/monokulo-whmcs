<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The settings page's Test connection: how a `send-test-webhook` went, as
 * the query string the callback sends the admin back to the settings page
 * with, and the notice the settings page shows for it.
 *
 * - `delivered`: WHMCS's receiver answered 2xx, with its status and how long
 *   it took.
 * - `failed`: it didn't: the status it answered (none when nothing did),
 *   Monokulo's error, and when Monokulo tries again.
 * - `rate-limited`: past the store's 6 tests a minute; how long to wait.
 * - `not-found`: Monokulo has no webhook for this WHMCS (connect again).
 * - `refused`: any other problem, with its status and detail.
 * - `unreachable`, `unoffered` (a Monokulo without the form),
 *   `not-connected`.
 */
final class TestWebhook {
	/** How much of Monokulo's error is carried back to the settings page. */
	private const MAX_ERROR = 300;

	private const KINDS = ['delivered', 'failed', 'rate-limited', 'not-found', 'refused', 'unreachable', 'unoffered', 'not-connected'];

	/**
	 * The query for the form's 200 answer.
	 *
	 * @param array<string, mixed> $answer
	 */
	public static function fromAnswer(array $answer): string {
		$query = [
			'monokulo_test'       => ($answer['delivered'] ?? false) === true ? 'delivered' : 'failed',
			'monokulo_test_event' => is_string($answer['event_id'] ?? null) ? $answer['event_id'] : '',
			'monokulo_test_ms'    => is_int($answer['duration_ms'] ?? null) ? $answer['duration_ms'] : 0,
		];
		if (is_int($answer['status'] ?? null)) {
			$query['monokulo_test_status'] = $answer['status'];
		}
		if ($query['monokulo_test'] === 'failed') {
			if (is_string($answer['error'] ?? null) && $answer['error'] !== '') {
				$query['monokulo_test_error'] = self::clip($answer['error']);
			}
			if (is_int($answer['next_attempt_at'] ?? null)) {
				$query['monokulo_test_next'] = $answer['next_attempt_at'];
			}
		}

		return self::build($query);
	}

	/** The query for a problem the form answered. */
	public static function fromProblem(ApiError $e): string {
		if ($e->problem === 'rate-limited' || ($e->problem === '' && $e->status === 429)) {
			return self::build(['monokulo_test' => 'rate-limited', 'monokulo_test_wait' => $e->retryAfter ?? 60]);
		}
		if ($e->problem === 'not-found') {
			return self::build(['monokulo_test' => 'not-found']);
		}

		return self::build(['monokulo_test' => 'refused', 'monokulo_test_status' => $e->status, 'monokulo_test_error' => self::clip($e->detail)]);
	}

	/** The query for an outcome with nothing more to say: `unreachable`, `unoffered`, `not-connected`. */
	public static function outcome(string $kind): string {
		return self::build(['monokulo_test' => $kind]);
	}

	/**
	 * The settings page's notice for the query it was opened with, in
	 * WHMCS's admin alert style, or '' when it isn't back from a test.
	 *
	 * @param array<string, mixed> $query
	 */
	public static function notice(array $query): string {
		$kind = is_string($query['monokulo_test'] ?? null) ? $query['monokulo_test'] : '';
		if (!in_array($kind, self::KINDS, true)) {
			return '';
		}
		$text = static fn(string $name): string => is_string($query[$name] ?? null) ? $query[$name] : '';
		$number = static fn(string $name): ?int => preg_match('/\A\d{1,12}\z/', $text($name)) ? (int)$text($name) : null;

		[$style, $message] = match ($kind) {
			'delivered'     => ['success', sprintf(Text::get('test_delivered'), $number('monokulo_test_status') ?? 200, $number('monokulo_test_ms') ?? 0, $text('monokulo_test_event'))],
			'failed'        => ['danger', self::failed($number('monokulo_test_status'), $text('monokulo_test_error'), $number('monokulo_test_next'))],
			'rate-limited'  => ['warning', sprintf(Text::get('test_rate_limited'), $number('monokulo_test_wait') ?? 60)],
			'refused'       => ['danger', sprintf(Text::get('test_refused'), $number('monokulo_test_status') ?? 0, $text('monokulo_test_error'))],
			default         => [$kind === 'unoffered' ? 'warning' : 'danger', Text::get('test_' . str_replace('-', '_', $kind))],
		};

		return '<div class="alert alert-' . $style . '" role="alert">' . Text::escape($message) . '</div>';
	}

	private static function failed(?int $status, string $error, ?int $next): string {
		$why = $status !== null ? sprintf(Text::get('test_answered'), $status) : Text::get('test_no_answer');
		$message = sprintf(Text::get('test_failed'), $why . ($error !== '' ? ': ' . $error : ''));
		if ($next !== null) {
			$message .= ' ' . sprintf(Text::get('test_retry'), date('Y-m-d H:i:s', $next));
		}

		return $message;
	}

	private static function clip(string $text): string {
		// By characters, not bytes, without needing mbstring.
		if (!preg_match('/\A.{' . self::MAX_ERROR . '}(?=.)/su', $text, $m)) {
			return $text;
		}

		return $m[0] . '…';
	}

	/** @param array<string, string|int> $query */
	private static function build(array $query): string {
		return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}
}
