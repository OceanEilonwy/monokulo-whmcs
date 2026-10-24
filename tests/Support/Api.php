<?php
namespace Monokulo\Whmcs\Tests\Support;

/**
 * Monokulo's HTTP API as the stand-in Monokulo answers it: the entry, an
 * order and a problem, shaped as Monokulo's own (the smoke suite checks
 * these against the OpenAPI document of a real Monokulo, its examples and
 * its answers).
 *
 * `prefix` is a Monokulo served under a path (`/monokulo`): every link and
 * form target starts with it. `onion` is its onion address, when it has
 * one: the entry links it (`mk:onion-api`) and orders their checkout there
 * (`mk:onion-checkout`), absolute and without the prefix.
 */
final class Api {
	public const HAL_FORMS = 'application/prs.hal-forms+json';
	public const PROBLEM = 'application/problem+json';
	public const ONION = 'http://2gzyxa5ihm7nsggfxnu52rck2vv4rvmdlkiu3zzui5du4xyclen53wid.onion';
	public const ETAG = '"0123456789abcdef0123456789abcdef"';

	/**
	 * The problems Monokulo's OpenAPI records for `POST /api/v1/orders`
	 * (create-order), by name, copied as they are: the smoke suite checks each
	 * against the document's example.
	 */
	public const ORDER_PROBLEMS = [
		'invalid-amount' => ['type' => 'https://docs.monokulo.com/problems/invalid-amount', 'title' => 'The order can\'t be priced', 'status' => 400, 'detail' => 'unknown currency: NOPE'],
		'unauthorized' => ['type' => 'https://docs.monokulo.com/problems/unauthorized', 'title' => 'No valid key', 'status' => 401, 'detail' => 'This key was not accepted. Check it, or connect the store or app again.'],
		'forbidden' => ['type' => 'https://docs.monokulo.com/problems/forbidden', 'title' => 'Not allowed with this key', 'status' => 403, 'detail' => 'This store only accepts orders from its verified websites, or from its own server using the store\'s secret key.'],
		'idempotency-conflict' => ['type' => 'https://docs.monokulo.com/problems/idempotency-conflict', 'title' => 'Key used for another order', 'status' => 409, 'detail' => 'this idempotency_key was already used for a different order'],
		'rate-limited' => ['type' => 'https://docs.monokulo.com/problems/rate-limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Rate limit exceeded; retry in 93 s.', 'retry_after' => 93],
		'internal' => ['type' => 'https://docs.monokulo.com/problems/internal', 'title' => 'Internal error', 'status' => 500, 'detail' => 'Something went wrong on this Monokulo. Try again in a moment.'],
		'engine-unavailable' => ['type' => 'https://docs.monokulo.com/problems/engine-unavailable', 'title' => 'Payment engine unavailable', 'status' => 503, 'detail' => 'The payment engine is busy or unreachable. Try again in a moment.'],
		'rates-unavailable' => ['type' => 'https://docs.monokulo.com/problems/rates-unavailable', 'title' => 'No exchange rate right now', 'status' => 503, 'detail' => 'There\'s no exchange rate for USD right now: the rate providers are busy or unreachable. Try again in 42 s.', 'retry_after' => 42],
		'store-unavailable' => ['type' => 'https://docs.monokulo.com/problems/store-unavailable', 'title' => 'Store unavailable', 'status' => 503, 'detail' => 'This store\'s settings can\'t be read right now. Please try again shortly.'],
	];

	/** The same for the connect flow's `/finish`. */
	public const FINISH_PROBLEMS = [
		'invalid-request' => ['type' => 'https://docs.monokulo.com/problems/invalid-request', 'title' => 'Invalid request', 'status' => 400, 'detail' => 'A webhook URL must start with http:// or https://.'],
		'invalid-connect-code' => ['type' => 'https://docs.monokulo.com/problems/invalid-connect-code', 'title' => 'Connect code not valid', 'status' => 401, 'detail' => 'This connect code is unknown, already used or expired. Start connecting again.'],
		'rate-limited' => ['type' => 'https://docs.monokulo.com/problems/rate-limited', 'title' => 'Too many requests', 'status' => 429, 'detail' => 'Rate limit exceeded; retry in 93 s.', 'retry_after' => 93],
		'internal' => ['type' => 'https://docs.monokulo.com/problems/internal', 'title' => 'Internal error', 'status' => 500, 'detail' => 'Something went wrong on this Monokulo. Try again in a moment.'],
		'no-public-address' => ['type' => 'https://docs.monokulo.com/problems/no-public-address', 'title' => 'No public address yet', 'status' => 503, 'detail' => 'This Monokulo instance can\'t connect plugins yet: its operator hasn\'t set its public address (the public URI setting on the admin settings page). Plugins need it to create orders and send customers to the checkout. Ask the operator to set it, then try connecting again.'],
	];

	/** The entry, `GET {prefix}/api/v1`, as the store's secret key sees it. */
	public static function entry(string $prefix = '', bool $onion = false): array {
		$links = [
			'curies'          => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
			'self'            => ['href' => $prefix . '/api/v1'],
			'mk:store'        => ['href' => $prefix . '/api/v1/store', 'title' => 'The store the key belongs to, and where its payments go.'],
			'mk:orders'       => ['href' => $prefix . '/api/v1/orders', 'title' => 'The store\'s orders, newest first, a page at a time.'],
			'mk:events'       => ['href' => $prefix . '/api/v1/events', 'type' => 'text/event-stream'],
			'service-desc'    => ['href' => $prefix . '/api/v1/openapi.json', 'type' => 'application/openapi+json'],
			'service-doc'     => ['href' => 'https://docs.monokulo.com/api/', 'type' => 'text/html'],
		];
		if ($onion) {
			$links['mk:onion-api'] = ['href' => self::ONION . '/api/v1'];
		}

		return [
			'_links'     => $links,
			'_templates' => [
				'create-order' => [
					'title'       => 'Make an order.',
					'method'      => 'POST',
					'target'      => $prefix . '/api/v1/orders',
					'contentType' => 'application/json',
					'properties'  => [
						['name' => 'amount', 'required' => true, 'type' => 'text'],
						['name' => 'currency', 'required' => true, 'type' => 'text'],
						['name' => 'merchant_order_id', 'type' => 'text'],
						['name' => 'description', 'type' => 'text'],
						['name' => 'show_merchant_order_id', 'type' => 'checkbox'],
						['name' => 'idempotency_key', 'type' => 'text'],
					],
				],
				'send-logs' => [
					'title'       => 'Send the shop\'s warnings and errors.',
					'method'      => 'POST',
					'target'      => $prefix . '/api/v1/logs',
					'contentType' => 'application/json',
					'properties'  => [
						['name' => 'entries', 'required' => true, 'type' => 'text'],
					],
				],
			],
		];
	}

	/** How Monokulo lets the entry be kept. @return array<string, string> */
	public static function entryHeaders(): array {
		return ['Content-Type' => self::HAL_FORMS, 'Cache-Control' => 'private, max-age=3600', 'Vary' => 'Authorization', 'ETag' => self::ETAG];
	}

	/** An order, as `create-order` answers it (201) to the store's secret key. */
	public static function order(string $id = 'order_1', string $prefix = '', bool $onion = false): array {
		$links = [
			'curies'             => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
			'self'               => ['href' => $prefix . '/api/v1/orders/' . $id],
			'collection'         => ['href' => $prefix . '/api/v1/orders'],
			'mk:order-events'    => ['href' => $prefix . '/api/v1/orders/' . $id . '/events', 'type' => 'text/event-stream'],
			'mk:checkout'        => ['href' => $prefix . '/checkout/' . $id, 'type' => 'text/html'],
			'mk:dashboard-order' => ['href' => $prefix . '/stores/9f1c/orders/' . $id, 'type' => 'text/html'],
		];
		if ($onion) {
			$links['mk:onion-checkout'] = ['href' => self::ONION . '/checkout/' . $id, 'type' => 'text/html'];
		}

		return [
			'order_id'                 => $id,
			'status'                   => 'pending',
			'is_final'                 => false,
			'address'                  => '8AfUwcnoJiRDMQKoUQMbNaFMWQtSyCKKXtqWAzTjWxzz',
			'xmr_amount_piconero'      => 600000000000,
			'amount_received_piconero' => 0,
			'amount'                   => '100.00',
			'currency'                 => 'USD',
			'merchant_order_id'        => '1',
			'description'              => 'Invoice #1',
			'show_merchant_order_id'   => true,
			'confirmations'            => 0,
			'confirmations_required'   => 10,
			'created_at'               => 1760000000,
			'expires_at'               => 1760003600,
			'_links'                   => $links,
		];
	}

	/**
	 * An order with none of the fields that may have no value: Monokulo
	 * leaves such a field out rather than sending `null` (an order made
	 * without an amount, a merchant order id or a description, and not
	 * cancelled).
	 */
	public static function bareOrder(string $id = 'order_1', string $prefix = '', bool $onion = false): array {
		return array_diff_key(self::order($id, $prefix, $onion), array_flip(self::OPTIONAL_ORDER_FIELDS));
	}

	/** An order's fields that are left out when they have no value. */
	public const OPTIONAL_ORDER_FIELDS = ['amount', 'currency', 'merchant_order_id', 'description', 'cancelled_at'];

	/** A problem (`application/problem+json`). */
	public static function problem(string $slug, int $status, string $title, string $detail): array {
		return ['type' => 'https://docs.monokulo.com/problems/' . $slug, 'title' => $title, 'status' => $status, 'detail' => $detail];
	}

	/** The stand-in Monokulo serves the entry and makes `orderId` (or answers `problem`). */
	public static function serve(FakeMonokulo $monokulo, string $orderId = 'order_1', ?array $problem = null, string $prefix = '', bool $onion = false): void {
		$monokulo->respond('GET', $prefix . '/api/v1', 200, self::entry($prefix, $onion), headers: self::entryHeaders());
		if ($problem) {
			$monokulo->respond('POST', $prefix . '/api/v1/orders', $problem['status'], $problem, headers: ['Content-Type' => self::PROBLEM]);
		} else {
			$monokulo->respond('POST', $prefix . '/api/v1/orders', 201, self::order($orderId, $prefix, $onion), headers: ['Content-Type' => self::HAL_FORMS, 'Location' => $prefix . '/api/v1/orders/' . $orderId]);
		}
	}

	/** The stand-in Monokulo answers the connect flow's `/finish` with `problem`. */
	public static function refuseFinish(FakeMonokulo $monokulo, array $problem, array $headers = []): void {
		$monokulo->respond('POST', '/plugins/connect/whmcs/finish', $problem['status'], $problem, headers: ['Content-Type' => self::PROBLEM] + $headers);
	}
}
