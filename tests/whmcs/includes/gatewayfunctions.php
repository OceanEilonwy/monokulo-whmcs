<?php
/** The stand-in for WHMCS's includes/gatewayfunctions.php: see init.php. */

if (!function_exists('getGatewayVariables')) {
	/**
	 * The gateway's settings (`type` is empty unless it is activated), and
	 * the system's.
	 *
	 * @return array<string, mixed>
	 */
	function getGatewayVariables(string $gateway, int $invoiceId = 0): array {
		$db = \WHMCS\Database\Capsule::connection();
		$params = ['type' => ''];
		foreach ($db->table('tblpaymentgateways')->where('gateway', $gateway)->get() as $row) {
			$params[$row->setting] = $row->value;
		}
		$params['paymentmethod'] = $gateway;
		$params['systemurl'] = (string)$db->table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
		$params['companyname'] = (string)$db->table('tblconfiguration')->where('setting', 'CompanyName')->value('value');
		$params['langpaynow'] = 'Pay Now';

		return $params;
	}

	/**
	 * Billing > Gateway Log. WHMCS writes an array as `key => value` lines.
	 *
	 * @param array<string, mixed>|string $data
	 */
	function logTransaction(string $gateway, array|string $data, string $result): void {
		if (is_array($data)) {
			$lines = [];
			foreach ($data as $key => $value) {
				$lines[] = $key . ' => ' . (is_scalar($value) ? $value : json_encode($value));
			}
			$data = implode("\n", $lines);
		}
		\WHMCS\Database\Capsule::table('tblgatewaylog')->insert(['date' => date('Y-m-d H:i:s'), 'gateway' => $gateway, 'data' => $data, 'result' => $result]);
	}
}
