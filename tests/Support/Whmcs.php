<?php
namespace Monokulo\Whmcs\Tests\Support;

use Monokulo\Whmcs\Plugin;
use WHMCS\Database\Capsule;

/**
 * The stand-in WHMCS (tests/whmcs), as the tests set it up and read it:
 * its database, its clients and invoices, its logs. Shared with the
 * stand-in's web server, which the http and smoke suites talk to.
 */
final class Whmcs {
	public const URL = 'http://localhost:8091/';
	public const HOST = 'localhost:8091';
	public const CALLBACK = self::URL . 'modules/gateways/callback/monokulo.php';

	/** Loads the stand-in in this process: what a callback has after init.php. */
	public static function boot(): void {
		require_once __DIR__ . '/../whmcs/init.php';
		require_once __DIR__ . '/../whmcs/includes/gatewayfunctions.php';
		require_once __DIR__ . '/../whmcs/includes/invoicefunctions.php';
		require_once __DIR__ . '/../../src/modules/gateways/monokulo.php';
	}

	/**
	 * Back to a WHMCS with the gateway activated and nothing else: no
	 * clients, invoices, payments, logs, settings, or connection.
	 */
	public static function reset(): void {
		self::boot();
		foreach (['tblclients', 'tblinvoices', 'tblinvoiceitems', 'tblaccounts', 'tblgatewaylog', 'tblactivitylog', 'tblmodulelog', 'mod_monokulo_connection', 'mod_monokulo_order', 'mod_monokulo_event', 'mod_monokulo_nonce'] as $table) {
			if (Capsule::schema()->hasTable($table)) {
				Capsule::table($table)->truncate();
			}
		}
		if (Capsule::schema()->hasTable('mod_monokulo_setting')) {
			Capsule::table('mod_monokulo_setting')->where('name', '<>', 'link_key')->delete();
		}
		Capsule::table('tblpaymentgateways')->where('gateway', Plugin::MODULE)->whereNotIn('setting', ['name', 'type', 'visible'])->delete();
		Capsule::table('tblconfiguration')->updateOrInsert(['setting' => 'SystemURL'], ['value' => self::URL]);
		$_SESSION = [];
	}

	/** Saves one of the gateway's settings, as its settings page does. */
	public static function setting(string $name, string $value): void {
		Capsule::table('tblpaymentgateways')->where('gateway', Plugin::MODULE)->where('setting', $name)->delete();
		Capsule::table('tblpaymentgateways')->insert(['gateway' => Plugin::MODULE, 'setting' => $name, 'value' => $value, 'order' => 1]);
	}

	/** Deactivated: WHMCS deletes the gateway's settings. */
	public static function deactivate(): void {
		Capsule::table('tblpaymentgateways')->where('gateway', Plugin::MODULE)->delete();
	}

	public static function activate(): void {
		foreach (['name' => 'Monokulo (Monero)', 'type' => 'Invoices', 'visible' => 'on'] as $name => $value) {
			self::setting($name, $value);
		}
	}

	/**
	 * Connected as the connect flow leaves it (the gateway makes its tables
	 * first), to the Monokulo at `monokulo`: its API's entry is `/api/v1` there.
	 */
	public static function connect(string $monokulo, string $webhookSecret, string $host = self::HOST): void {
		(new \Monokulo\Whmcs\Repository(Capsule::connection()))->install();
		Capsule::table('mod_monokulo_connection')->insert([
			'host'               => $host,
			'api'                => $monokulo . '/api/v1',
			'public_key'         => 'pk_test',
			'secret_token'       => 'sk_test',
			'webhook_secret'     => $webhookSecret,
			'connected_at'       => 1760000000,
		]);
	}

	/** A client paying in `currency`; returns their id. */
	public static function client(string $currency = 'USD'): int {
		return (int)Capsule::table('tblclients')->insertGetId([
			'firstname' => 'Ada',
			'lastname'  => 'Lovelace',
			'email'     => 'ada@example.test',
			'currency'  => (int)Capsule::table('tblcurrencies')->where('code', $currency)->value('id'),
		]);
	}

	/**
	 * An unpaid invoice of `items` (description => amount) for a new client
	 * paying in `currency`, to be paid with Monokulo; returns its id.
	 *
	 * @param array<string, string> $items
	 */
	public static function invoice(array $items = ['Web hosting - example.com' => '100.00'], string $currency = 'USD', string $invoiceNumber = ''): int {
		$client = self::client($currency);
		$total = number_format(array_sum(array_map('floatval', $items)), 2, '.', '');
		$id = (int)Capsule::table('tblinvoices')->insertGetId([
			'userid'        => $client,
			'invoicenum'    => $invoiceNumber,
			'date'          => '2026-10-01',
			'duedate'       => '2026-10-15',
			'subtotal'      => $total,
			'total'         => $total,
			'status'        => 'Unpaid',
			'paymentmethod' => Plugin::MODULE,
		]);
		foreach ($items as $description => $amount) {
			Capsule::table('tblinvoiceitems')->insert(['invoiceid' => $id, 'userid' => $client, 'type' => 'Hosting', 'description' => $description, 'amount' => $amount]);
		}

		return $id;
	}

	public static function invoiceStatus(int $id): string {
		return (string)Capsule::table('tblinvoices')->where('id', $id)->value('status');
	}

	/** @return list<array<string, mixed>> */
	public static function rows(string $table, array $where = []): array {
		$query = Capsule::table($table);
		foreach ($where as $column => $value) {
			$query->where($column, $value);
		}

		return array_map(static fn(object $row): array => (array)$row, $query->orderBy(Capsule::schema()->hasColumn($table, 'id') ? 'id' : Capsule::schema()->getColumnListing($table)[0])->get()->all());
	}

	/** @return list<array<string, mixed>> the payments on WHMCS's books */
	public static function payments(int $invoiceId): array {
		return self::rows('tblaccounts', ['invoiceid' => $invoiceId]);
	}

	/** @return list<array{data: string, result: string}> */
	public static function gatewayLog(): array {
		return array_map(static fn(array $row): array => ['data' => $row['data'], 'result' => $row['result']], self::rows('tblgatewaylog'));
	}

	/** Everything the Activity Log and Module Log hold, one string. */
	public static function logs(): string {
		$lines = array_column(self::rows('tblactivitylog'), 'description');
		foreach (self::rows('tblmodulelog') as $row) {
			$lines[] = $row['response'];
		}

		return implode("\n", $lines);
	}
}
