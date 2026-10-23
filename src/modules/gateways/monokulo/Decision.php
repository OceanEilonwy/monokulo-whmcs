<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * What one webhook event does to the WHMCS invoice.
 *
 * Every event is written to WHMCS's gateway log with `result` (its Result
 * column) and `note` (a Text key). `paid` adds the payment to the invoice,
 * which marks it paid; `expired` (an expired or cancelled order: it takes no
 * payment any more) lets the next Pay with Monero start a new Monokulo order
 * instead of returning to that one.
 */
final class Decision {
	public function __construct(
		public readonly string $result,
		public readonly string $note,
		public readonly bool $paid = false,
		public readonly bool $expired = false,
	) {
	}
}
