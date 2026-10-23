<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/** What the callback answers. */
final class Response {
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
	) {
	}

	public static function redirect(string $url): self {
		return new self(303, ['Location' => $url], '');
	}

	public static function text(int $status, string $text): self {
		return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
	}

	/** A small page of its own for the client or admin: the callback is outside WHMCS's templates. */
	public static function page(int $status, string $messageKey, ?string $back = null): self {
		$link = $back !== null ? '<p><a href="' . Text::escape($back) . '">' . Text::html('text_back') . '</a></p>' : '';
		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . Text::html('error_title') . '</title>'
			. '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem;line-height:1.5}</style></head>'
			. '<body><h1>' . Text::html('error_title') . '</h1><p>' . Text::html($messageKey) . '</p>' . $link . '</body></html>';

		return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
	}

	public function send(): void {
		http_response_code($this->status);
		foreach ($this->headers as $name => $value) {
			header($name . ': ' . $value);
		}
		echo $this->body;
	}
}
