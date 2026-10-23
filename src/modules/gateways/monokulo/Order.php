<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * A Monokulo order as the API answered it: its id; where the payer pays it
 * (`mk:checkout`), and on Monokulo's onion address (`mk:onion-checkout`,
 * while it has one). Both are absolute, resolved against the URL that
 * answered.
 */
final class Order {
	public function __construct(
		public readonly string $id,
		public readonly string $checkoutUrl,
		public readonly ?string $onionCheckoutUrl = null,
	) {
	}
}
