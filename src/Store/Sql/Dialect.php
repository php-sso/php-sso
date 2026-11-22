<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;

/**
 * Where the databases the SQL stores run on differ. The stores write the SQL both share and ask this for the rest, so a
 * database is one dialect (and one schema), not a copy of every store.
 */
interface Dialect
{
    /** `sqlite`, `mysql` or `pgsql`. */
    public function name(): string;

    /**
     * An INSERT with `?` placeholders for the columns that does nothing when the row exists already (`$keyColumns` say what
     * makes it exist).
     *
     * @param list<string> $columns
     * @param list<string> $keyColumns
     */
    public function insertIgnore(string $table, array $columns, array $keyColumns): string;

    /**
     * An INSERT with `?` placeholders for the columns that updates the other columns when the row exists already.
     *
     * @param list<string> $columns
     * @param list<string> $keyColumns
     */
    public function upsert(string $table, array $columns, array $keyColumns): string;

    /**
     * Like {@see insertIgnore()}, for the rows a SELECT returns (the SQL of a complete SELECT, in the order of `$columns`).
     *
     * @param list<string> $columns
     * @param list<string> $keyColumns
     */
    public function insertIgnoreSelect(string $table, array $columns, array $keyColumns, string $select): string;

    /** The id the last insert into the table made. */
    public function lastInsertId(PDO $pdo, string $table): int;

    /** The column as something to compare or sort ignoring case. Pair a comparison with {@see ciValue()} on the other side. */
    public function ci(string $column): string;

    /** A placeholder (or value expression) to compare with a {@see ci()} column. */
    public function ciValue(string $placeholder): string;

    /** A condition: the column equals the value, where NULL equals NULL (and nothing else). */
    public function sameOrNull(string $column, string $placeholder): string;

    /** A condition: the column contains the pattern (which the caller escaped with a backslash), ignoring case. */
    public function like(string $column, string $placeholder): string;

    public function castText(string $expression): string;

    public function castInt(string $expression): string;

    /** Within a transaction, lets foreign keys be checked at its end instead of per statement. */
    public function deferForeignKeys(PDO $pdo): void;

    /** Undoes {@see deferForeignKeys()} where that cannot be undone by the end of the transaction alone. */
    public function restoreForeignKeys(PDO $pdo): void;

    /** What a failed statement means, whatever the database calls it: `duplicate` (a unique value is taken), `reference` (it points at nothing), or null. */
    public function classify(PDOException $e): ?string;

    /**
     * Makes email addresses unique (ignoring case), or stops requiring it. Fails, naming the addresses, when the data
     * already has duplicates.
     */
    public function enforceUniqueEmail(PDO $pdo, bool $unique): void;

    /** Whether DDL (CREATE, ALTER, DROP) can be rolled back with the transaction around it: SQLite and PostgreSQL yes, MySQL no. */
    public function transactionalDdl(): bool;

    /**
     * Starts a migration: where {@see transactionalDdl()}, opens the transaction it runs in (SQLite also switches foreign
     * keys off first, which it ignores inside a transaction, and takes its write lock at once); elsewhere does nothing.
     */
    public function beginMigration(PDO $pdo): void;

    /** Ends a migration that went well (SQLite first checks that no foreign key is broken). Throws, having rolled back, when that fails. */
    public function commitMigration(PDO $pdo): void;

    /** Ends a migration that failed: rolls back where it can, and puts back what {@see beginMigration()} changed. Safe to call with nothing open. */
    public function rollbackMigration(PDO $pdo): void;

    /** Waits (up to `$seconds`) for the lock that lets one process at a time migrate; throws when it cannot get it. Pair with {@see unlock()}. */
    public function lock(PDO $pdo, int $seconds): void;

    public function unlock(PDO $pdo): void;

    public function tableExists(PDO $pdo, string $table): bool;

    public function columnExists(PDO $pdo, string $table, string $column): bool;

    public function indexExists(PDO $pdo, string $table, string $index): bool;

    /** Creates the table that records which migrations ran (`version`, `applied_at`) when it is not there. */
    public function createMigrationsTable(PDO $pdo, string $table): void;
}
