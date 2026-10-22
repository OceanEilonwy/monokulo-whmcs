<?php
/**
 * Sets up the stand-in WHMCS's database (run from the command line; safe to
 * run again): the tables of WHMCS's that the gateway and this stand-in read
 * and write, with the columns they use, and a WHMCS at
 * http://localhost:8091/ with two currencies and the gateway activated.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require __DIR__ . '/init.php';

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;

$tables = [
	'tblconfiguration' => static function (Blueprint $t): void {
		$t->string('setting', 64)->primary();
		$t->text('value');
	},
	'tblpaymentgateways' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->string('gateway', 64);
		$t->string('setting', 64);
		$t->text('value');
		$t->integer('order')->default(0);
	},
	'tblcurrencies' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->string('code', 8);
		$t->string('prefix', 8)->default('');
		$t->string('suffix', 8)->default('');
		$t->decimal('rate', 10, 5)->default(1);
		$t->boolean('default')->default(false);
	},
	'tblclients' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->string('firstname', 64);
		$t->string('lastname', 64);
		$t->string('email', 128);
		$t->integer('currency');
	},
	'tblinvoices' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->integer('userid');
		$t->string('invoicenum', 64)->default('');
		$t->date('date');
		$t->date('duedate');
		$t->dateTime('datepaid')->nullable();
		$t->decimal('subtotal', 16, 2);
		$t->decimal('total', 16, 2);
		$t->string('status', 32);
		$t->string('paymentmethod', 64);
	},
	'tblinvoiceitems' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->integer('invoiceid');
		$t->integer('userid');
		$t->string('type', 32)->default('');
		$t->integer('relid')->default(0);
		$t->text('description');
		$t->decimal('amount', 16, 2);
	},
	'tblaccounts' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->integer('userid');
		$t->string('gateway', 64);
		$t->dateTime('date');
		$t->text('description');
		$t->decimal('amountin', 16, 2)->default(0);
		$t->decimal('fees', 16, 2)->default(0);
		$t->string('transid', 191);
		$t->integer('invoiceid');
	},
	'tblgatewaylog' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->dateTime('date');
		$t->string('gateway', 64);
		$t->text('data');
		$t->string('result', 64);
	},
	'tblactivitylog' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->dateTime('date');
		$t->text('description');
		$t->string('user', 64);
		$t->integer('userid');
	},
	'tblmodulelog' => static function (Blueprint $t): void {
		$t->increments('id');
		$t->dateTime('date');
		$t->string('module', 64);
		$t->string('action', 64);
		$t->text('request');
		$t->text('response');
		$t->text('arrdata');
	},
];

$schema = Capsule::schema();
foreach ($tables as $name => $columns) {
	if (!$schema->hasTable($name)) {
		$schema->create($name, $columns);
	}
}

foreach (['SystemURL' => 'http://localhost:8091/', 'CompanyName' => 'Example Hosting'] as $setting => $value) {
	Capsule::table('tblconfiguration')->updateOrInsert(['setting' => $setting], ['value' => $value]);
}
if (!Capsule::table('tblcurrencies')->exists()) {
	Capsule::table('tblcurrencies')->insert([['code' => 'USD', 'prefix' => '$', 'default' => true], ['code' => 'EUR', 'prefix' => '€', 'default' => false]]);
}
// Activated, as Setup > Payments > Payment Gateways does: its name and type.
Capsule::table('tblpaymentgateways')->where('gateway', 'monokulo')->delete();
Capsule::table('tblpaymentgateways')->insert([
	['gateway' => 'monokulo', 'setting' => 'name', 'value' => 'Monokulo (Monero)', 'order' => 1],
	['gateway' => 'monokulo', 'setting' => 'type', 'value' => 'Invoices', 'order' => 1],
	['gateway' => 'monokulo', 'setting' => 'visible', 'value' => 'on', 'order' => 1],
]);

echo "The stand-in WHMCS is set up at http://localhost:8091/\n";
