<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The API's entry (`GET {api}`) as the store's secret key sees it: its links
 * and forms, kept for as long as Monokulo's `Cache-Control` says
 * (`private, max-age=3600`), with its `ETag` to ask again cheaply.
 *
 * `fresh` is true when Monokulo gave or confirmed it in this request: a form
 * from a fresh entry that is refused is refused, while one from a kept copy
 * means reading the entry again first.
 */
final class Entry {
	/**
	 * @param array<string, mixed> $body the entry's JSON
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $body,
		public readonly string $etag,
		public readonly int $expiresAt,
		public readonly bool $fresh = false,
	) {
	}

	/** The same entry, confirmed (`304`) or read now, kept until `expiresAt`. */
	public function renewed(int $expiresAt): self {
		return new self($this->url, $this->body, $this->etag, $expiresAt, true);
	}

	/**
	 * A form the key may use: its method and its target, resolved against
	 * the entry's URL. Null when the key isn't offered it.
	 *
	 * @return array{method: string, target: string}|null
	 */
	public function form(string $name): ?array {
		$form = $this->body['_templates'][$name] ?? null;
		if (!is_array($form) || !is_string($form['target'] ?? null) || $form['target'] === '') {
			return null;
		}

		return [
			'method' => is_string($form['method'] ?? null) ? strtoupper($form['method']) : 'POST',
			'target' => Url::resolve($this->url, $form['target']),
		];
	}

	/** A link's href, resolved against the entry's URL, or null. */
	public function link(string $rel): ?string {
		$href = $this->body['_links'][$rel]['href'] ?? null;

		return is_string($href) && $href !== '' ? Url::resolve($this->url, $href) : null;
	}

	/**
	 * How long an answer may be kept, from its `Cache-Control` (`max-age`),
	 * in seconds: 0 when it says `no-store` or `no-cache`, or nothing.
	 */
	public static function maxAge(string $cacheControl): int {
		if (preg_match('/(?:^|,)\s*no-(?:store|cache)\s*(?:,|$)/i', $cacheControl)) {
			return 0;
		}

		return preg_match('/(?:^|,)\s*max-age\s*=\s*"?(\d+)"?/i', $cacheControl, $m) ? (int)$m[1] : 0;
	}
}
