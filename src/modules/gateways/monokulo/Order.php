<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/** A Monokulo order as the API answered it: its id, and where the payer pays it. */
final class Order {
	public function __construct(
		public readonly string $id,
		public readonly string $checkoutUrl,
	) {
	}
}
