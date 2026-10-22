<?php
/**
 * Monokulo for WHMCS: take Monero through your own Monokulo, the
 * self-hosted, non-custodial Monero payment processor.
 *
 * A third-party payment gateway module: WHMCS finds it by this file's name
 * (`monokulo`). The work is in modules/gateways/monokulo/, and webhooks and
 * the connect flow come in at modules/gateways/callback/monokulo.php.
 *
 * Monokulo never holds the money, so there is no refund function: refund
 * from your wallet yourself.
 */
if (!defined('WHMCS')) {
	die('This file cannot be accessed directly');
}

require_once __DIR__ . '/monokulo/autoload.php';

/** @return array<string, mixed> */
function monokulo_MetaData(): array {
	return \Monokulo\Whmcs\Gateway::metaData();
}

/** @return array<string, array<string, mixed>> */
function monokulo_config(): array {
	return (new \Monokulo\Whmcs\Gateway(new \Monokulo\Whmcs\Whmcs()))->config($_GET, (string)($_SERVER['SCRIPT_NAME'] ?? ''), time());
}

/** @param array<string, mixed> $params */
function monokulo_config_validate(array $params): void {
	\Monokulo\Whmcs\Gateway::validate($params);
}

/** @param array<string, mixed> $params */
function monokulo_link(array $params): string {
	return (new \Monokulo\Whmcs\Gateway(new \Monokulo\Whmcs\Whmcs()))->link($params);
}
