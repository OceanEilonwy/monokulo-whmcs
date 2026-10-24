<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Monokulo answered, but not with what was asked for. `status` is its HTTP
 * status (2xx when the answer was a success with the wrong shape), `detail`
 * what went wrong (a problem's `detail`, or the raw body), and `problem` the
 * problem's name when the answer was `application/problem+json`: the last
 * part of its `type` (`https://docs.monokulo.com/problems/invalid-amount` is
 * `invalid-amount`), whose page says what it means. `retryAfter` is how
 * many seconds to wait before trying again, when Monokulo said (the
 * problem's `retry_after`, or `Retry-After`).
 */
final class ApiError extends \RuntimeException {
	/**
	 * Nothing was made, and the same request may work shortly: no exchange
	 * rate for the currency right now, the payment engine is busy, or the
	 * store's settings couldn't be read for a moment.
	 */
	private const TRY_AGAIN = ['rates-unavailable', 'engine-unavailable', 'store-unavailable'];

	/** Monokulo couldn't read what this gateway sent: a fault here, not the merchant's. */
	private const UNREADABLE = ['invalid-request', 'too-large', 'unsupported-media-type'];

	public function __construct(
		public readonly int $status,
		public readonly string $detail,
		public readonly string $problem = '',
		public readonly string $type = '',
		public readonly ?int $retryAfter = null,
	) {
		parent::__construct(sprintf('HTTP %d%s: %s', $status, $problem !== '' ? ' ' . $problem : '', $detail));
	}

	/**
	 * The error a failed answer carries: a problem+json body's `detail` (or
	 * its `title`), `type` and `retry_after` (else the `Retry-After` header,
	 * in seconds), else the body.
	 */
	public static function from(int $status, string $body, string $retryAfterHeader = ''): self {
		$retryAfter = preg_match('/\A\d{1,9}\z/', trim($retryAfterHeader)) ? (int)trim($retryAfterHeader) : null;
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['type']) && is_string($data['type'])) {
			$detail = is_string($data['detail'] ?? null) && $data['detail'] !== '' ? $data['detail'] : (is_string($data['title'] ?? null) ? $data['title'] : $body);
			$slug = basename((string)parse_url($data['type'], PHP_URL_PATH));
			if (is_int($data['retry_after'] ?? null) && $data['retry_after'] >= 0) {
				$retryAfter = $data['retry_after'];
			}

			return new self($status, $detail, $slug, $data['type'], $retryAfter);
		}

		return new self($status, $body, retryAfter: $retryAfter);
	}

	/**
	 * Whether the same request is worth sending again shortly: nothing was
	 * made, and the client pressing Pay with Monero again sends it with the
	 * same idempotency key.
	 */
	public function tryAgain(): bool {
		return in_array($this->problem, self::TRY_AGAIN, true);
	}

	/** The language key of what the customer is told. */
	public function customerMessageKey(): string {
		return match (true) {
			$this->problem === 'invalid-amount'             => 'error_currency',
			$this->problem === 'rates-unavailable'          => 'error_rates',
			$this->status === 429                           => 'error_busy',
			$this->status === 503                           => 'error_busy',
			$this->status === 401 || $this->status === 403  => 'error_unavailable',
			default                                         => 'error_generic',
		};
	}

	/** A pointer for the merchant reading the log, or ''. */
	public function merchantHint(): string {
		$hint = match (true) {
			$this->status === 401                      => 'the store secret key was rejected: connect again from the Monokulo gateway settings',
			$this->problem === 'invalid-amount'        => 'check that your Monokulo can price the invoice\'s currency',
			$this->problem === 'idempotency-conflict'  => 'Monokulo already has an order under this invoice\'s payment key for a different amount',
			$this->problem === 'rates-unavailable'     => 'Monokulo has no exchange rate for the invoice\'s currency right now (its rate providers are down or rate limiting it); no order was made, and the client was asked to try again shortly' . $this->wait(),
			$this->problem === 'engine-unavailable'    => 'Monokulo\'s payment engine is busy; no order was made, and the client was asked to try again shortly' . $this->wait(),
			$this->problem === 'store-unavailable'     => 'Monokulo couldn\'t read the store\'s settings (its database didn\'t answer while it checked who may make orders); no order was made, and the client was asked to try again shortly. Monokulo only checks that for orders made without the store secret key, so if this keeps happening, connect again from the Monokulo gateway settings, and tell the Monokulo operator their database is failing',
			$this->problem === 'logs-off'              => 'the store has client logs off: turn them on in the store\'s settings on Monokulo, or turn off "Send errors to Monokulo" in the gateway settings',
			$this->problem === 'challenge-required'    => 'Monokulo asked for a proof-of-work challenge, which it only asks of callers without the store secret key: connect again from the Monokulo gateway settings',
			in_array($this->problem, self::UNREADABLE, true) => 'Monokulo could not read what this gateway sent: update the Monokulo gateway module, and report it if it keeps happening',
			$this->problem === 'internal'              => 'something went wrong on Monokulo itself: its operator can find it in Monokulo\'s logs',
			$this->status === 403                      => 'Monokulo refused the store secret key for this: connect again from the Monokulo gateway settings',
			$this->status === 429                      => 'rate limited: Monokulo is receiving too many requests from this WHMCS',
			$this->status === 503                      => 'Monokulo is unavailable: try again shortly',
			default                                    => '',
		};
		if ($hint === '' && $this->type === '') {
			return '';
		}

		return ' (' . $hint . ($hint !== '' && $this->type !== '' ? '; ' : '') . $this->type . ')';
	}

	private function wait(): string {
		return $this->retryAfter !== null ? sprintf(' (Monokulo suggests waiting %d seconds)', $this->retryAfter) : '';
	}
}
