<?php
namespace Monokulo\Whmcs;

/** The parts of a WHMCS invoice the gateway uses, from its local API. */
final class Invoice {
	/**
	 * @param string $number the invoice number the client sees (WHMCS's custom
	 *                       invoice number when sequential numbering is on, else its id)
	 * @param list<string> $items the line items' descriptions
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $number,
		public readonly string $status,
		public readonly string $balance,
		public readonly string $currency,
		public readonly array $items,
	) {
	}

	/** Unpaid with something left to pay. */
	public function payable(): bool {
		return $this->status === 'Unpaid' && (float)$this->balance > 0;
	}
}
