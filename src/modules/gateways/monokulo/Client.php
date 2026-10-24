<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * create an order, send its webhook a test delivery (the settings page's
 * Test connection), and (when the merchant turned it on) send its warnings
 * and errors.
 *
 * Orders go through Monokulo's HTTP API (HAL-FORMS): the store's secret key
 * in `Authorization: Bearer` picks the store, and the API's entry, which
 * the connect flow gave, offers the forms it may use (`create-order`,
 * `send-logs`, `send-test-webhook`). The entry is kept as its `Cache-Control` says (see
 * EntryCache), so making an order is usually one request; it is read again
 * when it has expired (with `If-None-Match`), and at once when a form from
 * a kept copy is refused with 401, 404 or 405. Every address is a link or
 * form target, resolved against the URL it came from: nothing is built
 * here but the connect flow's own. Errors come back as
 * `application/problem+json` (ApiError).
 */
final class Client {
	public const HAL_FORMS = 'application/prs.hal-forms+json';

	/** A form from a kept entry answering one of these means: read the entry again, then retry. */
	private const STALE = [401, 404, 405];

	public function __construct(
		private readonly Trace $trace,
		private readonly EntryCache $cache,
		private readonly int $now,
	) {
	}

	/**
	 * `POST {address}/plugins/connect/whmcs/finish`: redeems the single-use token
	 * the connect flow handed back, registers `webhookUrl`, and returns the
	 * store's credentials, with `api` (the API's entry) resolved against the
	 * address it was called at, and `onion_api` (the entry on Monokulo's
	 * onion address) when Monokulo has one.
	 *
	 * @return array{public_key: string, secret_token: string, api: string, onion_api: ?string, webhook_signing_secret: string}
	 *
	 * @throws ApiError|TransportError
	 */
	public function finishConnect(string $address, string $token, string $webhookUrl): array {
		$url = rtrim($address, '/') . '/plugins/connect/' . Plugin::SLUG . '/finish';
		$response = $this->request('POST', $url, $this->headers('application/json'), (string)json_encode([
			'token'       => $token,
			'webhook_url' => $webhookUrl,
			'name'        => Plugin::NAME,
		]), 30);
		$data = $this->decode($response, [200]);
		foreach (['public_key', 'secret_token', 'api', 'webhook_signing_secret'] as $key) {
			if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
				throw new ApiError($response->status, 'missing ' . $key . ': ' . $response->body);
			}
		}
		$onion = $data['onion_api'] ?? null;

		return [
			'public_key'             => $data['public_key'],
			'secret_token'           => $data['secret_token'],
			'api'                    => Url::resolve($url, $data['api']),
			'onion_api'              => is_string($onion) && $onion !== '' ? Url::resolve($url, $onion) : null,
			'webhook_signing_secret' => $data['webhook_signing_secret'],
		];
	}

	/**
	 * Where the API's entry is on Monokulo's onion address (the entry's
	 * `mk:onion-api`, from the kept copy while it holds), or null when
	 * Monokulo has no onion address: then no order has an onion checkout.
	 *
	 * @throws ApiError|TransportError
	 */
	public function onionApi(Connection $connection): ?string {
		$entry = $this->entry($connection, 30, false);
		if ($entry->link('mk:onion-api') === null && !$entry->fresh) {
			// Before refusing anyone over it, make sure: a Monokulo that has
			// just gained an onion address says so now (304 while it hasn't).
			$entry = $this->entry($connection, 30, false, true);
		}

		return $entry->link('mk:onion-api');
	}

	/**
	 * Creates an order with the entry's `create-order` form, and returns it
	 * with its links.
	 *
	 * @param array<string, mixed> $body see OrderRequest
	 *
	 * @throws ApiError|TransportError
	 */
	public function createOrder(Connection $connection, array $body): Order {
		[$response, $target] = $this->submit($connection, 'create-order', $body, 30);
		if ($response === null) {
			throw new ApiError(200, 'the API entry offers this key no create-order form');
		}
		$order = $this->decode($response, [200, 201]);
		$id = $order['order_id'] ?? null;
		$link = static function (string $rel) use ($order, $target): ?string {
			$href = $order['_links'][$rel]['href'] ?? null;

			return is_string($href) && $href !== '' ? Url::resolve($target, $href) : null;
		};
		$checkout = $link('mk:checkout');
		if (!is_string($id) || $id === '' || $checkout === null) {
			throw new ApiError($response->status, 'no order_id or mk:checkout link: ' . $response->body);
		}

		return new Order($id, $checkout, $link('mk:onion-checkout'));
	}

	/**
	 * Has Monokulo send this WHMCS's webhook (the one it registered when it
	 * connected) a signed `webhook.test` delivery now, with the entry's
	 * `send-test-webhook` form, and returns how it went once the receiver
	 * has answered: `delivered`, `status`, `duration_ms`, `error`,
	 * `next_attempt_at`, `event_id` (see WebhookTest). Null when the key
	 * isn't offered the form (a Monokulo from before it).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws ApiError|TransportError
	 */
	public function sendTestWebhook(Connection $connection): ?array {
		[$response] = $this->submit($connection, 'send-test-webhook', [], 30);
		if ($response === null) {
			return null;
		}
		$answer = $this->decode($response, [200]);
		if (!is_bool($answer['delivered'] ?? null) || !is_string($answer['event_id'] ?? null)) {
			throw new ApiError($response->status, 'no delivered or event_id: ' . $response->body);
		}

		return $answer;
	}

	/**
	 * The shop's warnings and errors, for the merchant to read in Monokulo,
	 * through the entry's `send-logs` form. Best effort: nothing is sent when
	 * the key isn't offered the form, and an unreachable Monokulo is ignored
	 * (these are reports about other failures). Monokulo refusing them
	 * (client logs off for the store, the key refused, rate limited) is
	 * returned, for the merchant to read in WHMCS's own log.
	 *
	 * @param list<array{level: string, message: string, trace_id: string}> $entries
	 */
	public function forwardLogs(Connection $connection, array $entries): ?ApiError {
		if (!$entries) {
			return null;
		}
		try {
			[$response] = $this->submit($connection, 'send-logs', ['entries' => $entries], 3);
			if ($response !== null && ($response->status < 200 || $response->status > 299)) {
				return ApiError::from($response->status, $response->body, $response->headers['retry-after'] ?? '');
			}
		} catch (ApiError $e) {
			return $e;
		} catch (TransportError) {
			// Nothing to do: these are reports about other failures.
		}

		return null;
	}

	/**
	 * Sends `body` with the entry's form `name`: its answer and the URL it
	 * came from, or [null, ''] when the key isn't offered that form. A form
	 * from a kept entry that is refused (401, 404, 405), or missing, is tried
	 * once more from the entry read again.
	 *
	 * @param array<string, mixed> $body
	 *
	 * @return array{0: ?HttpResponse, 1: string}
	 *
	 * @throws ApiError|TransportError
	 */
	private function submit(Connection $connection, string $name, array $body, int $timeoutSeconds): array {
		$entry = $this->entry($connection, $timeoutSeconds, false);
		while (true) {
			$form = $entry->form($name);
			if ($form !== null) {
				// An empty body is the empty object (`{}`), never a list.
				$json = $body === [] ? '{}' : (string)json_encode($body);
				$response = $this->request($form['method'], $form['target'], $this->headers('application/json', $connection), $json, $timeoutSeconds);
				if (!in_array($response->status, self::STALE, true) || $entry->fresh) {
					return [$response, $form['target']];
				}
			} elseif ($entry->fresh) {
				return [null, ''];
			}
			$entry = $this->entry($connection, $timeoutSeconds, true);
		}
	}

	/**
	 * The API's entry for this connection's key: the kept copy while it
	 * holds (unless `revalidate`), else read (with `If-None-Match` when there
	 * is a copy, unless `again`, which reads it whole) and kept.
	 *
	 * @throws ApiError|TransportError
	 */
	private function entry(Connection $connection, int $timeoutSeconds, bool $again, bool $revalidate = false): Entry {
		$kept = $again ? null : $this->cache->entry($connection);
		if ($kept && $kept->expiresAt > $this->now && !$revalidate) {
			return $kept;
		}
		$headers = $this->headers(null, $connection);
		if ($kept && $kept->etag !== '') {
			$headers['If-None-Match'] = $kept->etag;
		}
		$response = $this->request('GET', $connection->api, $headers, null, $timeoutSeconds);
		$expires = $this->now + Entry::maxAge($response->headers['cache-control'] ?? '');
		if ($kept && $response->status === 304) {
			$entry = $kept->renewed($expires);
		} else {
			$entry = new Entry($connection->api, $this->decode($response, [200]), $response->headers['etag'] ?? '', $expires, true);
		}
		$this->cache->keepEntry($connection, $entry);

		return $entry;
	}

	/**
	 * One request through PHP's curl extension (which WHMCS requires).
	 *
	 * @param array<string, string> $headers
	 *
	 * @throws TransportError when no answer came back (refused, timed out, TLS)
	 */
	private function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse {
		$lines = [];
		foreach ($headers as $name => $value) {
			$lines[] = $name . ': ' . $value;
		}
		$received = [];
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
			CURLOPT_TIMEOUT        => $timeoutSeconds,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$name = strtolower(trim($parts[0]));
					// A header sent more than once (Vary) is one list.
					$received[$name] = isset($received[$name]) ? $received[$name] . ', ' . trim($parts[1]) : trim($parts[1]);
				}

				return strlen($line);
			},
		]);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		$answer = curl_exec($curl);
		if ($answer === false) {
			$error = curl_error($curl);

			throw new TransportError($error !== '' ? $error : 'no response');
		}

		return new HttpResponse((int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$answer, $received);
	}

	/** @return array<string, string> */
	private function headers(?string $contentType, ?Connection $connection = null): array {
		$headers = [
			'Accept'          => self::HAL_FORMS . ', application/json, application/problem+json',
			'Monokulo-Client' => Plugin::client(),
			'traceparent'     => $this->trace->traceparent(),
		];
		if ($contentType !== null) {
			$headers['Content-Type'] = $contentType;
		}
		if ($connection) {
			$headers['Authorization'] = 'Bearer ' . $connection->secretToken;
		}

		return $headers;
	}

	/**
	 * The JSON object a success carries.
	 *
	 * @param list<int> $expected the statuses that are a success here
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ApiError
	 */
	private function decode(HttpResponse $response, array $expected): array {
		if (!in_array($response->status, $expected, true)) {
			throw ApiError::from($response->status, $response->body, $response->headers['retry-after'] ?? '');
		}
		$data = json_decode($response->body, true);
		if (!is_array($data)) {
			throw new ApiError($response->status, 'not JSON: ' . $response->body);
		}

		return $data;
	}
}
