<?php
namespace Monokulo\Whmcs\Tests\Support;

/**
 * Monokulo's HTTP API as the stand-in Monokulo answers it: the entry, an
 * order and a problem, shaped as Monokulo's own (the smoke suite's
 * ApiContractTest checks these against the OpenAPI document a real Monokulo
 * serves).
 */
final class Api {
	public const HAL_FORMS = 'application/prs.hal-forms+json';
	public const PROBLEM = 'application/problem+json';

	/** The entry, `GET /api/v1`, as the store's secret key sees it. */
	public static function entry(): array {
		return [
			'_links' => [
				'curies'          => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
				'self'            => ['href' => '/api/v1'],
				'mk:store'        => ['href' => '/api/v1/store', 'title' => 'The store the key belongs to, and where its payments go.'],
				'mk:orders'       => ['href' => '/api/v1/orders', 'title' => 'The store\'s orders, newest first, a page at a time.'],
				'mk:events'       => ['href' => '/api/v1/events', 'type' => 'text/event-stream'],
				'service-desc'    => ['href' => '/api/v1/openapi.json', 'type' => 'application/openapi+json'],
				'service-doc'     => ['href' => 'https://docs.monokulo.com/api/', 'type' => 'text/html'],
			],
			'_templates' => [
				'create-order' => [
					'title'       => 'Make an order.',
					'method'      => 'POST',
					'target'      => '/api/v1/orders',
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
			],
		];
	}

	/** An order, as `create-order` answers (201) and its `self` link reads it. */
	public static function order(string $id = 'order_1'): array {
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
			'_links'                   => [
				'curies'          => [['name' => 'mk', 'href' => 'https://docs.monokulo.com/rels/{rel}', 'templated' => true]],
				'self'            => ['href' => '/api/v1/orders/' . $id],
				'collection'      => ['href' => '/api/v1/orders'],
				'mk:order-events' => ['href' => '/api/v1/orders/' . $id . '/events', 'type' => 'text/event-stream'],
				'mk:checkout'     => ['href' => '/checkout/' . $id, 'type' => 'text/html'],
			],
		];
	}

	/** A problem (`application/problem+json`). */
	public static function problem(string $slug, int $status, string $title, string $detail): array {
		return ['type' => 'https://docs.monokulo.com/problems/' . $slug, 'title' => $title, 'status' => $status, 'detail' => $detail];
	}

	/** The stand-in Monokulo serves the entry and makes `orderId` (or answers `problem`). */
	public static function serve(FakeMonokulo $monokulo, string $orderId = 'order_1', ?array $problem = null): void {
		$monokulo->respond('GET', '/api/v1', 200, self::entry(), headers: ['Content-Type' => self::HAL_FORMS]);
		if ($problem) {
			$monokulo->respond('POST', '/api/v1/orders', $problem['status'], $problem, headers: ['Content-Type' => self::PROBLEM]);
		} else {
			$monokulo->respond('POST', '/api/v1/orders', 201, self::order($orderId), headers: ['Content-Type' => self::HAL_FORMS, 'Location' => '/api/v1/orders/' . $orderId]);
		}
	}
}
