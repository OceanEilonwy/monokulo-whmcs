<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/**
 * Everything the gateway says, in English. WHMCS's own language files
 * don't reach a gateway's callback, so the words live here.
 */
final class Text {
	private const TEXT = [
		// Settings (Setup > Payments > Payment Gateways)
		'display_name'              => 'Monokulo (Monero)',
		'entry_address'             => 'Monokulo address',
		'help_address'              => 'Where your Monokulo is, as your browser opens it: https://www.monokulo.com, or your own Monokulo\'s address. Save, then press Connect below.',
		'entry_forward_errors'      => 'Send errors to Monokulo',
		'help_forward_errors'       => 'Also send this gateway\'s warnings and errors to your Monokulo, when the store there accepts client logs. They are always in WHMCS\'s Activity Log.',
		'text_connected_as'         => 'Connected as %s since %s.',
		'text_not_connected'        => 'Not connected: clients are told Monero is not set up yet.',
		'text_address_first'        => 'Save your Monokulo address first, then connect.',
		'text_connected'            => 'WHMCS is connected. Clients can now pay invoices with Monero.',
		'text_webhook'              => 'Monokulo sends payment updates to %s.',
		'text_onion'                => 'Clients on your shop\'s .onion address pay on your Monokulo\'s onion address, %s.',
		'text_no_onion'             => 'Your Monokulo has no onion address: clients on a .onion address of your shop can\'t pay with Monero. If your Monokulo has one now, it shows here after the next payment, or connect again.',
		'button_connect'            => 'Connect',
		'button_reconnect'          => 'Connect again',
		'error_address'             => 'Enter your Monokulo address as an http or https address, such as https://www.monokulo.com.',
		'error_connect_address'     => 'Save your Monokulo address before connecting.',
		'error_connect_host'        => 'The connect flow came back to a different address than it started from (did the System URL change?). Please connect again.',
		'error_connect_finish'      => 'Monokulo did not hand over the store\'s keys. Please connect again; the Activity Log says why.',
		'error_connect_unavailable' => 'Your Monokulo cannot connect shops yet: its operator has not set its public address.',
		'error_connect_busy'        => 'Your Monokulo is busy right now. Wait a minute, then connect again.',
		'error_connect_unreachable' => 'Your Monokulo could not be reached from this server. Check the address, then connect again.',
		'error_connect_admin'       => 'Sign in to the WHMCS admin area, then press Connect again from the Monokulo gateway settings.',
		'error_connect_link'        => 'This Connect link has expired. Open the Monokulo gateway settings again and press Connect.',
		'text_connect_failed'       => 'This connect link has already been used or has expired. Start connecting again from the Monokulo gateway settings in your WHMCS admin area.',

		// The invoice (client area and invoice emails)
		'button_pay'                => 'Pay with Monero',
		'text_pay'                  => 'You pay on a secure Monokulo payment page next.',
		'text_unavailable'          => 'Monero payments are not set up yet. Please contact us to pay this invoice.',
		'text_invoice'              => 'Invoice #%s',
		'text_more'                 => ' + %d more',
		'text_back'                 => 'Back to the invoice',
		'error_title'               => 'Pay with Monero',
		'error_link'                => 'This payment link is not valid. Open the invoice again and press Pay with Monero.',
		'error_invoice'             => 'This invoice could not be found.',
		'error_not_payable'         => 'This invoice has nothing left to pay.',
		'error_not_connected'       => 'Monero payments are not set up yet. Please contact us to pay this invoice.',
		'error_busy'                => 'Monero payments are busy right now. Please wait a moment and try again.',
		'error_unavailable'         => 'Monero payments are not available right now. Please contact us.',
		'error_generic'             => 'The Monero payment could not be started. Please try again, or contact us.',
		'error_currency'            => 'This invoice\'s currency can\'t be paid in Monero right now. Please contact us.',
		'error_unreachable'         => 'The Monero payment service could not be reached. Please try again shortly.',
		'error_no_onion'            => 'Monero payments can\'t be made over Tor here yet. Please contact us to pay this invoice.',

		// Gateway log (Billing > Gateway Log)
		'note_started'              => 'Client sent to pay with Monero: %s',
		'note_waiting'              => 'A payment was seen and is waiting for confirmations.',
		'note_partial'              => 'Part of the amount due has arrived. The invoice is not paid in full yet.',
		'note_paid'                 => 'Paid in full. The payment was added to the invoice.',
		'note_overpaid'             => 'Paid, with more than the amount due. The payment was added to the invoice; the excess is not refunded automatically: refund it yourself if needed.',
		'note_already_recorded'     => 'Paid. This Monokulo order\'s payment was already on the invoice, so nothing was added.',
		'note_expired'              => 'The payment window closed without full payment. Any funds received stay at the payment address; refund them yourself if needed. Paying the invoice again starts a new Monokulo order.',
		'note_double_spend_detected' => 'FRAUD ALERT. A payment to this invoice was double-spent and no longer counts (transaction %s). If this changed the order\'s status, that is a separate entry. Check this invoice before delivering anything.',
		'note_double_spend_reversed' => 'The earlier double-spend alert for this invoice (transaction %s) was wrong and is withdrawn. If this changed the order\'s status, that is a separate entry.',
		'note_paid_older'           => 'This was an older payment request for this invoice (a newer one was started after it), paid all the same: check the amount against the invoice\'s balance.',
		'note_older'                => 'This is about an older payment request for this invoice, not its current one: nothing on the invoice changed.',
		'note_paid_again'           => 'Paid, but the invoice was already paid, so nothing was added. This is a second payment: refund it from your wallet if needed.',
		'note_overpaid_after_paid'  => 'More than the amount due has now arrived for this Monokulo order. Its payment was already on the invoice, so nothing was added: refund the excess yourself if needed.',
		'note_unknown_transaction'  => 'unknown',
	];

	public static function get(string $key): string {
		return self::TEXT[$key] ?? $key;
	}

	/** The text as HTML. */
	public static function html(string $key): string {
		return self::escape(self::get($key));
	}

	public static function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
	}
}
