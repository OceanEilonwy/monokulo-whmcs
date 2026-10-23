<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Monokulo answered, but not with what was asked for. `status` is its HTTP
 * status (2xx when the answer was a success with the wrong shape), `detail`
 * what went wrong (a problem's `detail`, or the raw body), and `problem` the
 * problem's name when the answer was `application/problem+json`: the last
 * part of its `type` (`https://docs.monokulo.com/problems/invalid-amount` is
 * `invalid-amount`), whose page says what it means.
 */
final class ApiError extends \RuntimeException {
	public function __construct(
		public readonly int $status,
		public readonly string $detail,
		public readonly string $problem = '',
		public readonly string $type = '',
	) {
		parent::__construct(sprintf('HTTP %d%s: %s', $status, $problem !== '' ? ' ' . $problem : '', $detail));
	}

	/**
	 * The error a failed answer carries: a problem+json body's `detail` (or
	 * its `title`) and `type`, a connect error's `{"error": …}`, else the body.
	 */
	public static function from(int $status, string $body): self {
		$data = json_decode($body, true);
		if (is_array($data) && isset($data['type']) && is_string($data['type'])) {
			$detail = is_string($data['detail'] ?? null) && $data['detail'] !== '' ? $data['detail'] : (is_string($data['title'] ?? null) ? $data['title'] : $body);
			$slug = basename((string)parse_url($data['type'], PHP_URL_PATH));

			return new self($status, $detail, $slug, $data['type']);
		}
		if (is_array($data) && isset($data['error']) && is_string($data['error'])) {
			return new self($status, $data['error']);
		}

		return new self($status, $body);
	}

	/** The language key of what the customer is told. */
	public function customerMessageKey(): string {
		return match (true) {
			$this->problem === 'invalid-amount'             => 'error_currency',
			$this->status === 429                           => 'error_busy',
			$this->status === 503                           => 'error_busy',
			$this->status === 401 || $this->status === 403  => 'error_unavailable',
			default                                         => 'error_generic',
		};
	}

	/** A pointer for the merchant reading the log, or ''. */
	public function merchantHint(): string {
		$hint = match (true) {
			$this->status === 401                     => 'the store secret key was rejected: connect again from the Monokulo gateway settings',
			$this->problem === 'invalid-amount'       => 'check that your Monokulo can price the invoice\'s currency',
			$this->problem === 'idempotency-conflict' => 'Monokulo already has an order under this invoice\'s payment key for a different amount',
			$this->status === 403                     => 'Monokulo refused the store secret key for this: connect again from the Monokulo gateway settings',
			$this->status === 429                     => 'rate limited: Monokulo is receiving too many requests from this WHMCS',
			$this->status === 503                     => 'Monokulo\'s payment engine is busy or unavailable: try again shortly',
			default                                   => '',
		};
		if ($hint === '' && $this->type === '') {
			return '';
		}

		return ' (' . $hint . ($hint !== '' && $this->type !== '' ? '; ' : '') . $this->type . ')';
	}
}
