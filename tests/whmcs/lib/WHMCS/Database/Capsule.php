<?php
namespace WHMCS\Database;

use Illuminate\Database\Capsule\Manager;

/**
 * WHMCS's Capsule: Laravel's database manager, connected to WHMCS's
 * database. Here, the tests' MariaDB (docker/compose.yaml).
 */
final class Capsule extends Manager {
	public static function boot(): void {
		if (static::$instance) {
			return;
		}
		$capsule = new self();
		$capsule->addConnection([
			'driver'    => 'mysql',
			'host'      => '127.0.0.1',
			'port'      => (int)(getenv('DB_PORT') ?: 33067),
			'database'  => 'whmcs',
			'username'  => 'whmcs',
			'password'  => 'whmcs',
			'charset'   => 'utf8mb4',
			'collation' => 'utf8mb4_unicode_ci',
			'prefix'    => '',
			'strict'    => false,
		]);
		$capsule->setAsGlobal();
	}
}
