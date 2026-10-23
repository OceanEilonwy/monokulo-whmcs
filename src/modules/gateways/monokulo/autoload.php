<?php
declare(strict_types=1);

/**
 * Loads the gateway's own classes (namespace Monokulo\Whmcs, one class per
 * file named after it). Most know nothing about WHMCS, so the unit tests
 * load them the same way without a WHMCS.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'Monokulo\\Whmcs\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
