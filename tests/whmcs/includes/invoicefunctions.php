<?php
/** The stand-in for WHMCS's includes/invoicefunctions.php: see init.php. */

if (!function_exists('addInvoicePayment')) {
	/**
	 * Records a payment against an invoice (a transaction in tblaccounts) and
	 * marks the invoice Paid once its payments cover the total, as WHMCS does
	 * (WHMCS then also sends the payment confirmation and runs its hooks).
	 */
	function addInvoicePayment(int $invoiceId, string $transactionId, mixed $amount, mixed $fees, string $gateway, bool $noEmail = false, mixed $date = null): bool {
		$db = \WHMCS\Database\Capsule::connection();
		$invoice = $db->table('tblinvoices')->where('id', $invoiceId)->first();
		if (!$invoice) {
			return false;
		}
		$db->table('tblaccounts')->insert([
			'userid'      => $invoice->userid,
			'gateway'     => $gateway,
			'date'        => date('Y-m-d H:i:s'),
			'description' => 'Invoice Payment',
			'amountin'    => (string)$amount,
			'fees'        => (string)$fees,
			'transid'     => $transactionId,
			'invoiceid'   => $invoiceId,
		]);
		$paid = (float)$db->table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
		if ($invoice->status === 'Unpaid' && $paid >= (float)$invoice->total) {
			$db->table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid', 'datepaid' => date('Y-m-d H:i:s')]);
		}

		return true;
	}
}
