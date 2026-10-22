<?php
namespace Monokulo\Whmcs;

/**
 * The body of `POST /pay/{pk}/orders` for one WHMCS invoice.
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
		$amount = self::amount($invoice->balance);
		$currency = strtoupper($invoice->currency);

		return [
			'amount'                 => $amount,
			'currency'               => $currency,
			'merchant_order_id'      => $invoice->number,
			'show_merchant_order_id' => true,
			'description'            => self::description($descriptionPrefix, $invoice->items, $moreFormat),
			'idempotency_key'        => sprintf('whmcs:%d:%s:%s:%d', $invoice->id, $amount, $currency, $attempt),
		];
	}

	/**
	 * The balance as a decimal string with two places, as WHMCS keeps every
	 * amount (an XMR currency in WHMCS included).
	 */
	public static function amount(string $balance): string {
		return number_format((float)$balance, 2, '.', '');
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
