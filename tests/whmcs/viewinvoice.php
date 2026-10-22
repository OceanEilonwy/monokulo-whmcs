<?php
/**
 * The stand-in's client-area invoice: what WHMCS shows of the invoice's
 * gateway, the output of monokulo_link() with WHMCS's parameters.
 */
require __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/modules/gateways/monokulo.php';

$invoice = localAPI('GetInvoice', ['invoiceid' => (int)($_GET['id'] ?? 0)]);
if ($invoice['result'] !== 'success') {
	http_response_code(404);
	exit('Invoice not found.');
}
$client = localAPI('GetClientsDetails', ['clientid' => $invoice['userid']]);
$params = getGatewayVariables('monokulo') + [
	'invoiceid'   => $invoice['invoiceid'],
	'invoicenum'  => $invoice['invoicenum'],
	'description' => 'Invoice #' . $invoice['invoiceid'],
	'amount'      => $invoice['balance'],
	'currency'    => $client['currency_code'],
	'returnurl'   => 'http://localhost:8091/viewinvoice.php?id=' . $invoice['invoiceid'],
];

echo '<!doctype html><html><head><meta charset="utf-8"><title>Invoice #' . (int)$invoice['invoiceid'] . '</title></head><body>';
echo '<h1>Invoice #' . (int)$invoice['invoiceid'] . '</h1><p class="status">' . htmlspecialchars($invoice['status']) . '</p>';
echo '<p class="balance">' . htmlspecialchars($invoice['balance'] . ' ' . $client['currency_code']) . '</p>';
if ($invoice['status'] === 'Unpaid') {
	echo '<div class="payment">' . monokulo_link($params) . '</div>';
}
echo '</body></html>';
