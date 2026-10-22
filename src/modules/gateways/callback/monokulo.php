<?php
/**
 * Monokulo's way into WHMCS: its webhooks, the connect flow's two steps, and
 * the invoices' Pay with Monero links. See Monokulo\Whmcs\Callback.
 */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../monokulo/autoload.php';

$gateway = getGatewayVariables(\Monokulo\Whmcs\Plugin::MODULE);
if (empty($gateway['type'])) {
	http_response_code(503);
	die('Module Not Activated');
}

(new \Monokulo\Whmcs\Callback(new \Monokulo\Whmcs\Whmcs(), $gateway, time()))->handle(\Monokulo\Whmcs\Request::fromGlobals())->send();
