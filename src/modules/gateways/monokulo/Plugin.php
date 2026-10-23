<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Who this plugin is, as monokulo sees it.
 */
final class Plugin {
	/** Kept equal to VERSION by a test. */
	public const VERSION = '0.2.0';

	/** The connect path's platform slug: `/plugins/connect/whmcs`. */
	public const SLUG = 'whmcs';

	/** What monokulo shows the merchant ("Connect your WHMCS shop"). */
	public const NAME = 'WHMCS';

	/** The gateway's module name in WHMCS (`modules/gateways/monokulo.php`). */
	public const MODULE = 'monokulo';

	/** The `Monokulo-Client` header value, `whmcs/<version>`. */
	public static function client(): string {
		return self::SLUG . '/' . self::VERSION;
	}
}
