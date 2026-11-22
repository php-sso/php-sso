<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use LogicException;
use PhpSso\Store\Sql\Connection;

/**
 * The base of a migration written once for all three databases: implement {@see up()} and branch on
 * `$db->dialect->name()` (`sqlite`, `mysql` or `pgsql`) where the SQL differs.
 *
 * Do not open a transaction of your own: the migrator does (where the database can roll back DDL). On MySQL it cannot, so
 * write each step to be safe to run twice: guard it with {@see Connection::tableExists()}, {@see Connection::columnExists()}
 * and {@see Connection::indexExists()}, or use the helpers here that already do.
 */
abstract class SqlMigration implements Migration
{
    abstract public function up(Connection $db): void;

    final public function run(Connection $db): void
    {
        $this->up($db);
    }

    /**
     * Runs SQL that is the same everywhere (a string) or per database (an array keyed `sqlite`, `mysql`, `pgsql`, with `*`
     * for the rest). A database with no entry is an error, so a forgotten dialect is not skipped quietly.
     *
     * @param string|array<string, string> $sql
     */
    protected function exec(Connection $db, string|array $sql): void
    {
        $statement = is_string($sql) ? $sql : ($sql[$db->dialect->name()] ?? $sql['*'] ?? throw new LogicException('Migration ' . $this->id() . ' has no SQL for ' . $db->dialect->name() . '.'));
        $db->pdo->exec($statement);
    }

    /** Adds a column unless it is there (safe to run again). `$definition` is the type and constraints, e.g. `BIGINT NOT NULL DEFAULT 0`. */
    protected function addColumn(Connection $db, string $table, string $column, string $definition): void
    {
        if (!$db->columnExists($table, $column)) {
            $db->pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $column . '" ' . $definition);
        }
    }
}
