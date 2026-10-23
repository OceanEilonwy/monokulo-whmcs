<?php
namespace Monokulo\Whmcs\Tests\Support;

use Monokulo\Whmcs\Connection;
use Monokulo\Whmcs\Entry;
use Monokulo\Whmcs\EntryCache;

/** The entry kept in memory, per key, for the client's own tests. */
final class MemoryEntryCache implements EntryCache {
	/** @var array<string, Entry> */
	public array $entries = [];

	public function entry(Connection $connection): ?Entry {
		return $this->entries[$connection->secretToken] ?? null;
	}

	public function keepEntry(Connection $connection, Entry $entry): void {
		$this->entries[$connection->secretToken] = $entry;
	}
}
