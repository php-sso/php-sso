<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What keeps a store healthy over time. */
interface Maintenance
{
    /** Drops what can never be used again: ended sessions, expired codes and tokens, old revocations and failures. Safe to run at any time, often. */
    public function purgeExpired(): void;

    /** Whether the store answers right now. Reads and writes nothing that matters, so a monitor may ask often. */
    public function isHealthy(): bool;

    /**
     * The ids of the schema migrations that have not run yet (empty: the schema is up to date).
     *
     * @return list<string>
     */
    public function pendingMigrations(): array;

    /**
     * The schema versions the storage has that this version of the software does not know: it was upgraded by a newer one, and this one must not serve from it.
     *
     * @return list<string>
     */
    public function unknownMigrations(): array;
}
