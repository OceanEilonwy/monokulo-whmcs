<?php
/**
 * A stand-in WHMCS, and its init.php.
 *
 * WHMCS is commercial and closed source, with no image to run in CI, so the
 * gateway's tests run it against this: the small part of WHMCS a payment
 * gateway touches, written from WHMCS's developer documentation.
 *
 * - `init.php`: what WHMCS's `init.php` gives a callback: the `WHMCS`
 *   constant, the database (`WHMCS\Database\Capsule`, Laravel's query builder
 *   as in WHMCS, on MariaDB), the session, `localAPI` (`GetInvoice`,
 *   `GetClientsDetails`), `logActivity`, `logModuleCall`.
 * - `includes/gatewayfunctions.php`: `getGatewayVariables`, `logTransaction`.
 * - `includes/invoicefunctions.php`: `addInvoicePayment`.
 * - `install.php`: the `tbl*` tables those read and write, and the settings
 *   of a WHMCS at `http://localhost:8091/` with the gateway activated.
 * - `admin/login.php`, `admin/configgateways.php`: an admin signs in and
 *   opens the gateway's settings, rendered from `monokulo_config()` and saved
 *   through `monokulo_config_validate()`.
 * - `viewinvoice.php`: an invoice page, with `monokulo_link()`.
 * - `modules`: the gateway, unzipped from dist/monokulo-whmcs.zip by
 *   `scripts/test install` (not committed).
 *
 * It is not WHMCS: what real WHMCS does beyond this surface (its templates,
 * emails, provisioning, admin permissions) isn't tested here. The README's
 * manual checklist covers that on a real install.
 */
if (!defined('WHMCS')) {
	define('WHMCS', true);
	define('ROOTDIR', __DIR__);
}

require_once __DIR__ . '/../../vendor/autoload.php';

\WHMCS\Database\Capsule::boot();

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
	session_start();
}

if (!function_exists('localAPI')) {
	/**
	 * WHMCS's internal API, for the two commands the gateway uses. Answers as
	 * WHMCS documents them; anything else is an error.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, mixed>
	 */
	function localAPI(string $command, array $values = [], string $adminUser = ''): array {
		$db = \WHMCS\Database\Capsule::connection();
		switch ($command) {
			case 'GetInvoice':
				$invoice = $db->table('tblinvoices')->where('id', (int)($values['invoiceid'] ?? 0))->first();
				if (!$invoice) {
					return ['result' => 'error', 'message' => 'Invoice ID Not Found'];
				}
				$paid = (float)$db->table('tblaccounts')->where('invoiceid', $invoice->id)->sum('amountin');
				$items = [];
				foreach ($db->table('tblinvoiceitems')->where('invoiceid', $invoice->id)->orderBy('id')->get() as $item) {
					$items[] = ['id' => $item->id, 'type' => $item->type, 'relid' => $item->relid, 'description' => $item->description, 'amount' => $item->amount, 'taxed' => 0];
				}

				return [
					'result'        => 'success',
					'invoiceid'     => $invoice->id,
					'invoicenum'    => $invoice->invoicenum,
					'userid'        => $invoice->userid,
					'date'          => $invoice->date,
					'duedate'       => $invoice->duedate,
					'datepaid'      => $invoice->datepaid,
					'subtotal'      => $invoice->subtotal,
					'total'         => $invoice->total,
					'balance'       => number_format((float)$invoice->total - $paid, 2, '.', ''),
					'status'        => $invoice->status,
					'paymentmethod' => $invoice->paymentmethod,
					'items'         => ['item' => $items],
				];
			case 'GetClientsDetails':
				$client = $db->table('tblclients')->where('id', (int)($values['clientid'] ?? 0))->first();
				if (!$client) {
					return ['result' => 'error', 'message' => 'Client Not Found'];
				}
				$code = $db->table('tblcurrencies')->where('id', $client->currency)->value('code');
				$details = ['userid' => $client->id, 'id' => $client->id, 'firstname' => $client->firstname, 'lastname' => $client->lastname, 'email' => $client->email, 'currency' => $client->currency, 'currency_code' => $code];

				return ['result' => 'success', 'client' => $details] + $details;
			default:
				return ['result' => 'error', 'message' => 'Command Not Found'];
		}
	}

	/** Utilities > Logs > Activity Log. */
	function logActivity(string $description, int $userId = 0): void {
		\WHMCS\Database\Capsule::table('tblactivitylog')->insert(['date' => date('Y-m-d H:i:s'), 'description' => $description, 'user' => 'System', 'userid' => $userId]);
	}

	/**
	 * Utilities > Logs > Module Log. WHMCS keeps these only while module
	 * debug logging is on; here, always.
	 *
	 * @param array<int, string> $replaceVars
	 */
	function logModuleCall(string $module, string $action, mixed $request, mixed $response, mixed $processedData = '', array $replaceVars = []): void {
		$text = static fn(mixed $v): string => is_string($v) ? $v : (string)json_encode($v);
		\WHMCS\Database\Capsule::table('tblmodulelog')->insert(['date' => date('Y-m-d H:i:s'), 'module' => $module, 'action' => $action, 'request' => $text($request), 'response' => $text($response), 'arrdata' => $text($processedData)]);
	}
}
