<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

use Illuminate\Database\Connection as Database;
use Illuminate\Database\Schema\Blueprint;

/**
 * The gateway's own tables, through WHMCS's database (Capsule's connection).
 *
 * - `mod_monokulo_connection`: one row per host that has connected (WHMCS's
 *   System URL's host).
 * - `mod_monokulo_order`: each Monokulo order WHMCS created, and which
 *   invoice it pays. An invoice can have more than one: when its balance
 *   changed after a first attempt, or an earlier one expired. The newest is
 *   the live one.
 * - `mod_monokulo_event`: webhook events already applied, so a delivery that
 *   is sent again (its answer was lost) changes nothing the second time.
 * - `mod_monokulo_nonce`: connect attempts an admin started, each good once
 *   and for ten minutes.
 * - `mod_monokulo_setting`: the key that signs the gateway's own links.
 *
 * Deactivating the gateway keeps these tables: invoices' payments refer to
 * them, and activating it again picks the connection back up.
 */
final class Repository {
	public const NONCE_TTL_SECONDS = 600;

	public function __construct(
		private readonly Database $db,
	) {
	}

	/** Creates the tables that don't exist yet. */
	public function install(): void {
		$schema = $this->db->getSchemaBuilder();
		$tables = [
			'mod_monokulo_connection' => static function (Blueprint $t): void {
				$t->string('host', 191)->primary();
				$t->string('endpoint', 255);
				$t->string('public_key', 128);
				$t->string('secret_token', 255);
				$t->string('webhook_secret', 255);
				$t->integer('connected_at');
			},
			'mod_monokulo_order' => static function (Blueprint $t): void {
				$t->increments('seq');
				$t->string('monokulo_order_id', 64)->unique();
				$t->integer('invoice_id')->index();
				$t->string('host', 191);
				$t->string('amount', 40);
				$t->string('currency', 8);
				$t->integer('attempt');
				$t->boolean('expired')->default(false);
				$t->integer('created_at');
			},
			'mod_monokulo_event' => static function (Blueprint $t): void {
				$t->string('event_id', 64)->primary();
				$t->string('monokulo_order_id', 64);
				$t->integer('applied_at');
			},
			'mod_monokulo_nonce' => static function (Blueprint $t): void {
				$t->char('nonce', 32)->primary();
				$t->string('host', 191);
				$t->text('admin_return');
				$t->integer('expires_at');
			},
			'mod_monokulo_setting' => static function (Blueprint $t): void {
				$t->string('name', 64)->primary();
				$t->text('value');
			},
		];
		foreach ($tables as $name => $columns) {
			if (!$schema->hasTable($name)) {
				$schema->create($name, $columns);
			}
		}
	}

	public function connection(string $host): ?Connection {
		$row = $this->db->table('mod_monokulo_connection')->where('host', $host)->first();
		if (!$row) {
			return null;
		}

		return new Connection(
			$row->host,
			$row->endpoint,
			$row->public_key,
			$row->secret_token,
			$row->webhook_secret,
			(int)$row->connected_at,
		);
	}

	public function saveConnection(Connection $c): void {
		$this->db->table('mod_monokulo_connection')->updateOrInsert(['host' => $c->host], [
			'endpoint'           => $c->endpoint,
			'public_key'         => $c->publicKey,
			'secret_token'       => $c->secretToken,
			'webhook_secret'     => $c->webhookSecret,
			'connected_at'       => $c->connectedAt,
		]);
	}

	/**
	 * The key the gateway signs its own links with (Pay with Monero, Connect),
	 * made the first time it is needed.
	 */
	public function linkKey(): string {
		$this->db->table('mod_monokulo_setting')->insertOrIgnore(['name' => 'link_key', 'value' => bin2hex(random_bytes(32))]);

		return (string)$this->db->table('mod_monokulo_setting')->where('name', 'link_key')->value('value');
	}

	/** Remembers a connect attempt an admin started; returns its nonce. */
	public function createNonce(string $host, string $adminReturn, int $now): string {
		$nonce = bin2hex(random_bytes(16));
		$this->db->table('mod_monokulo_nonce')->where('expires_at', '<', $now)->delete();
		$this->db->table('mod_monokulo_nonce')->insert([
			'nonce'        => $nonce,
			'host'         => $host,
			'admin_return' => $adminReturn,
			'expires_at'   => $now + self::NONCE_TTL_SECONDS,
		]);

		return $nonce;
	}

	/**
	 * Uses up a nonce: its attempt (`host`, `admin_return`) the first time it
	 * is presented within ten minutes, null ever after.
	 *
	 * @return array{host: string, admin_return: string}|null
	 */
	public function consumeNonce(string $nonce, int $now): ?array {
		if (!preg_match('/\A[0-9a-f]{32}\z/', $nonce)) {
			return null;
		}
		$row = $this->db->table('mod_monokulo_nonce')->where('nonce', $nonce)->first();
		// Only the request whose DELETE removed the row may use it.
		$deleted = $this->db->table('mod_monokulo_nonce')->where('nonce', $nonce)->delete();
		if (!$row || $deleted !== 1 || (int)$row->expires_at < $now) {
			return null;
		}

		return ['host' => $row->host, 'admin_return' => $row->admin_return];
	}

	/**
	 * Remembers a Monokulo order for an invoice. False when it was already
	 * known (the client pressed Pay with Monero again and got the same order).
	 */
	public function recordOrder(string $monokuloOrderId, int $invoiceId, string $host, string $amount, string $currency, int $attempt, int $now): bool {
		return $this->db->table('mod_monokulo_order')->insertOrIgnore([
			'monokulo_order_id' => $monokuloOrderId,
			'invoice_id'        => $invoiceId,
			'host'              => $host,
			'amount'            => $amount,
			'currency'          => $currency,
			'attempt'           => $attempt,
			'created_at'        => $now,
		]) === 1;
	}

	/**
	 * How many of an invoice's Monokulo orders have expired: the next one is
	 * a new attempt, so it gets a new idempotency key.
	 */
	public function attempt(int $invoiceId): int {
		return $this->db->table('mod_monokulo_order')->where('invoice_id', $invoiceId)->where('expired', true)->count();
	}

	public function markExpired(string $monokuloOrderId): void {
		$this->db->table('mod_monokulo_order')->where('monokulo_order_id', $monokuloOrderId)->update(['expired' => true]);
	}

	/**
	 * The invoice a Monokulo order pays, the amount it was for, and whether
	 * it is that invoice's newest Monokulo order.
	 *
	 * @return array{invoice_id: int, amount: string, currency: string, latest: bool}|null
	 */
	public function findOrder(string $monokuloOrderId): ?array {
		$row = $this->db->table('mod_monokulo_order')->where('monokulo_order_id', $monokuloOrderId)->first();
		if (!$row) {
			return null;
		}
		$newest = $this->db->table('mod_monokulo_order')->where('invoice_id', $row->invoice_id)->orderByDesc('seq')->value('monokulo_order_id');

		return [
			'invoice_id' => (int)$row->invoice_id,
			'amount'     => $row->amount,
			'currency'   => $row->currency,
			'latest'     => $newest === $row->monokulo_order_id,
		];
	}

	/**
	 * Claims a webhook event: true the first time `eventId` is seen, false for
	 * a repeat, so each event changes the invoice once.
	 */
	public function claimEvent(string $eventId, string $monokuloOrderId, int $now): bool {
		return $this->db->table('mod_monokulo_event')->insertOrIgnore([
			'event_id'          => $eventId,
			'monokulo_order_id' => $monokuloOrderId,
			'applied_at'        => $now,
		]) === 1;
	}

	/** Gives a claimed event back, after applying it failed, so a retry applies it. */
	public function releaseEvent(string $eventId): void {
		$this->db->table('mod_monokulo_event')->where('event_id', $eventId)->delete();
	}
}
