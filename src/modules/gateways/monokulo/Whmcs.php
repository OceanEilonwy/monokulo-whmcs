<?php
namespace Monokulo\Whmcs;

use Illuminate\Database\Connection as Database;
use WHMCS\Database\Capsule;

/**
 * Everything the gateway asks of WHMCS itself, in one place: its database,
 * its local API (GetInvoice, GetClientsDetails), and the functions a
 * gateway callback loads (addInvoicePayment, logTransaction, logActivity,
 * logModuleCall). The tests run this class against a stand-in for exactly
 * these (tests/whmcs).
 *
 * The callback doesn't use checkCbInvoiceID or checkCbTransID: both end the
 * request with die() when the check fails, and a webhook must answer with a
 * status Monokulo can act on (404 is retried, 200 is not).
 */
final class Whmcs {
	public function db(): Database {
		return Capsule::connection();
	}

	/** WHMCS's System URL (General Settings), with a trailing slash. */
	public function systemUrl(): string {
		$url = (string)Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');

		return rtrim($url, '/') . '/';
	}

	/** One of the gateway's settings, as saved on its settings page. */
	public function setting(string $name): string {
		return (string)Capsule::table('tblpaymentgateways')->where('gateway', Plugin::MODULE)->where('setting', $name)->value('value');
	}

	/** The invoice, through the local API, or null when there is none. */
	public function invoice(int $id): ?Invoice {
		$invoice = localAPI('GetInvoice', ['invoiceid' => $id]);
		if (($invoice['result'] ?? '') !== 'success') {
			return null;
		}
		$client = localAPI('GetClientsDetails', ['clientid' => (int)$invoice['userid'], 'stats' => false]);
		$currency = (string)($client['currency_code'] ?? $client['client']['currency_code'] ?? '');
		$items = [];
		foreach ($invoice['items']['item'] ?? [] as $item) {
			$items[] = (string)$item['description'];
		}
		$number = trim((string)($invoice['invoicenum'] ?? ''));

		return new Invoice((int)$invoice['invoiceid'], $number !== '' ? $number : (string)$invoice['invoiceid'], (string)$invoice['status'], (string)$invoice['balance'], $currency, $items);
	}

	/** Adds a payment to the invoice: WHMCS marks it paid when that covers it. */
	public function addPayment(int $invoiceId, string $transactionId, string $amount): void {
		addInvoicePayment($invoiceId, $transactionId, $amount, 0, Plugin::MODULE);
	}

	/** Whether a payment with this transaction id is already on WHMCS's books. */
	public function paymentRecorded(string $transactionId): bool {
		return Capsule::table('tblaccounts')->where('gateway', Plugin::MODULE)->where('transid', $transactionId)->exists();
	}

	/**
	 * One entry in the gateway log (Billing > Gateway Log).
	 *
	 * @param array<string, string> $data
	 */
	public function gatewayLog(array $data, string $result): void {
		logTransaction(Plugin::MODULE, $data, $result);
	}

	/**
	 * One log line: warnings and errors to the Activity Log (always kept),
	 * the rest to the Module Log (kept while module debug logging is on).
	 */
	public function log(string $level, string $line): void {
		if ($level === 'info') {
			logModuleCall(Plugin::MODULE, 'info', '', $line);
		} else {
			logActivity('Monokulo: ' . $line);
		}
	}

	/** Whether this browser is signed in to WHMCS's admin area. */
	public function adminSignedIn(): bool {
		return !empty($_SESSION['adminid']);
	}
}
