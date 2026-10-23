<?php
/**
 * The stand-in's Setup > Payments > Payment Gateways page, for the Monokulo
 * gateway only: its fields from monokulo_config() (a field not saved yet
 * shows its Default), saved through
 * monokulo_config_validate() into tblpaymentgateways, as WHMCS does.
 */
require __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/gatewayfunctions.php';
require_once __DIR__ . '/../modules/gateways/monokulo.php';

use WHMCS\Database\Capsule;

if (empty($_SESSION['adminid'])) {
	http_response_code(403);
	exit('Sign in first.');
}

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$fields = ['address' => (string)($_POST['address'] ?? ''), 'forwardErrors' => isset($_POST['forwardErrors']) ? 'on' : ''];
	try {
		monokulo_config_validate($fields);
		foreach ($fields as $setting => $value) {
			Capsule::table('tblpaymentgateways')->where('gateway', 'monokulo')->where('setting', $setting)->delete();
			Capsule::table('tblpaymentgateways')->insert(['gateway' => 'monokulo', 'setting' => $setting, 'value' => $value, 'order' => 1]);
		}
		$message = 'Changes Saved Successfully!';
	} catch (\WHMCS\Exception\Module\InvalidConfiguration $e) {
		http_response_code(422);
		$message = $e->getMessage();
	}
}

$saved = getGatewayVariables('monokulo');
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>Payment Gateways</title></head><body>';
echo '<p class="message">' . $e($message) . '</p>';
echo '<form method="post" action="configgateways.php"><table>';
foreach (monokulo_config() as $name => $field) {
	if ($field['Type'] === 'System') {
		echo '<tr><td colspan="2" class="' . $e($name) . '">' . ($name === 'UsageNotes' ? $field['Value'] : $e($field['Value'])) . '</td></tr>';
	} elseif ($field['Type'] === 'yesno') {
		$checked = ($saved[$name] ?? '') === 'on' ? ' checked' : '';
		echo '<tr><td>' . $e($field['FriendlyName']) . '</td><td><input type="checkbox" name="' . $e($name) . '"' . $checked . '> ' . $field['Description'] . '</td></tr>';
	} else {
		echo '<tr><td>' . $e($field['FriendlyName']) . '</td><td><input type="text" name="' . $e($name) . '" value="' . $e((string)($saved[$name] ?? $field['Default'] ?? '')) . '"> ' . $field['Description'] . '</td></tr>';
	}
}
echo '</table><button type="submit">Save Changes</button></form></body></html>';
