<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * The calls the shop's server makes to Monokulo: redeem a connect token,
 * create an order, and (when the merchant turned it on) forward its warnings
 * and errors.
 *
 * Orders go through Monokulo's HTTP API (HAL-FORMS): the store's secret key
 * in `Authorization: Bearer` picks the store, the entry the connect flow
 * gave says where its `create-order` form posts, and the order's `mk:checkout`
 * link is where the client pays. Errors come back as
 * `application/problem+json` (ApiError).
 */
final class Client {
	public const HAL_FORMS = 'application/prs.hal-forms+json';

	public function __construct(
		private readonly Trace $trace,
	) {
	}

	/**
	 * `POST {address}/plugins/connect/whmcs/finish`: redeems the single-use token
	 * the connect flow handed back, registers `webhookUrl`, and returns the
	 * store's credentials, with `api` (the API's entry) resolved against
	 * `address`.
	 *
	 * @return array{public_key: string, secret_token: string, api: string, webhook_signing_secret: string}
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

		return [
			'public_key'             => $data['public_key'],
			'secret_token'           => $data['secret_token'],
			'api'                    => Url::resolve($url, $data['api']),
			'webhook_signing_secret' => $data['webhook_signing_secret'],
		];
	}

	/**
	 * Creates an order: reads the API's entry with the store's secret key,
	 * follows its `create-order` form, and returns the order with its
	 * checkout link.
	 *
	 * @param array<string, mixed> $body see OrderRequest
	 *
	 * @throws ApiError|TransportError
	 */
	public function createOrder(Connection $connection, array $body): Order {
		$entry = $this->decode($this->request('GET', $connection->api, $this->headers(null, $connection), null, 30), [200]);
		$form = $entry['_templates']['create-order'] ?? null;
		if (!is_array($form) || !is_string($form['target'] ?? null) || $form['target'] === '') {
			throw new ApiError(200, 'the API entry has no create-order form for this key: ' . (string)json_encode($entry));
		}
		$method = is_string($form['method'] ?? null) ? strtoupper($form['method']) : 'POST';
		$target = Url::resolve($connection->api, $form['target']);
		$response = $this->request($method, $target, $this->headers('application/json', $connection), (string)json_encode($body), 30);
		$order = $this->decode($response, [200, 201]);
		$id = $order['order_id'] ?? null;
		$checkout = $order['_links']['mk:checkout']['href'] ?? null;
		if (!is_string($id) || $id === '' || !is_string($checkout) || $checkout === '') {
			throw new ApiError($response->status, 'no order_id or mk:checkout link: ' . $response->body);
		}
		$self = $response->headers['location'] ?? $order['_links']['self']['href'] ?? $target;

		return new Order($id, Url::resolve(Url::resolve($target, (string)$self), $checkout));
	}

	/**
	 * `POST {api}/logs`: the shop's warnings and errors, for the merchant to
	 * read in Monokulo. Best effort: a failure here is ignored.
	 *
	 * @param list<array{level: string, message: string, trace_id: string}> $entries
	 */
	public function forwardLogs(Connection $connection, array $entries): void {
		if (!$entries) {
			return;
		}
		try {
			$this->request('POST', $connection->logsUrl(), $this->headers('application/json', $connection), (string)json_encode(['entries' => $entries]), 3);
		} catch (TransportError) {
			// Nothing to do: these are reports about other failures.
		}
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
					$received[strtolower(trim($parts[0]))] = trim($parts[1]);
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
			throw ApiError::from($response->status, $response->body);
		}
		$data = json_decode($response->body, true);
		if (!is_array($data)) {
			throw new ApiError($response->status, 'not JSON: ' . $response->body);
		}

		return $data;
	}
}
