<?php
declare(strict_types=1);

namespace Monokulo\Whmcs;

/** Where the API's entry is kept between requests, per connection (one key each). */
interface EntryCache {
	/** The entry kept for this connection's key, or null. */
	public function entry(Connection $connection): ?Entry;

	public function keepEntry(Connection $connection, Entry $entry): void;
}
