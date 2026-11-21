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
}
