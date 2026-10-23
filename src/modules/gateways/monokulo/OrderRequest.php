<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * What the API's `create-order` form is sent for one WHMCS invoice.
 *
 * The amount is the invoice's balance in the client's currency, and
 * Monokulo converts it to XMR at its own rate: WHMCS never converts. The
 * invoice number goes along as the merchant's reference and is shown on the
 * checkout (the client knows their invoice by it). The idempotency key makes
 * a second press of Pay with Monero get the same Monokulo order, and a
 * changed balance or currency, or an expired earlier order, a new one.
 */
final class OrderRequest {
	public const MAX_DESCRIPTION_CHARS = 140;

	/** @return array<string, mixed> */
	public static function body(Invoice $invoice, int $attempt, string $descriptionPrefix, string $moreFormat): array {
		$currency = strtoupper($invoice->currency);
		$amount = self::amount($invoice->balance, $currency);

		return [
			'amount'                 => $amount,
			'currency'               => $currency,
			'merchant_order_id'      => $invoice->number,
			'show_merchant_order_id' => true,
			'description'            => self::description($descriptionPrefix, $invoice->items, $moreFormat),
			'idempotency_key'        => sprintf('whmcs:%d:%s:%s:%d', $invoice->id, $amount, $currency, $attempt),
		];
	}

	/** ISO 4217 currencies without minor units. */
	private const NO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

	/** ISO 4217 currencies with three decimals. */
	private const THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

	/** How many decimals the currency has: 0 for JPY, 3 for KWD, 12 for XMR, else 2. */
	public static function decimals(string $currency): int {
		$currency = strtoupper($currency);

		return match (true) {
			$currency === 'XMR'                             => 12,
			in_array($currency, self::NO_DECIMALS, true)    => 0,
			in_array($currency, self::THREE_DECIMALS, true) => 3,
			default                                         => 2,
		};
	}

	/**
	 * The balance as a plain decimal in the currency's own decimals, without
	 * trailing zeros (`12.50` EUR is `12.5`, `1000.00` JPY is `1000`). WHMCS
	 * keeps every amount with two places, so a currency with more is never
	 * padded out to them: Monokulo refuses more decimals than a currency has.
	 */
	public static function amount(string $balance, string $currency): string {
		$amount = number_format((float)$balance, 2, '.', '');
		if (self::decimals($currency) < 2) {
			$amount = number_format(round((float)$amount, self::decimals($currency)), self::decimals($currency), '.', '');
		}

		return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
	}

	/**
	 * "Invoice #1042: Web hosting + 2 more", on one line, at most 140
	 * characters.
	 *
	 * @param list<string> $itemNames
	 */
	public static function description(string $prefix, array $itemNames, string $moreFormat): string {
		$description = $prefix;
		if ($itemNames) {
			$description .= ': ' . $itemNames[0];
			if (count($itemNames) > 1) {
				$description .= sprintf($moreFormat, count($itemNames) - 1);
			}
		}
		$description = trim((string)preg_replace('/[\p{Cc}\s]+/u', ' ', $description));
		if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION_CHARS) {
			$description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION_CHARS - 1, 'UTF-8')) . '…';
		}

		return $description;
	}
}
