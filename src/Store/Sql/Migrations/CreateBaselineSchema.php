<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\MysqlSchema;
use PhpSso\Store\Sql\PostgresSchema;
use PhpSso\Store\Sql\SqliteSchema;

/**
 * The schema as it was when migrations began. Every statement is idempotent, so it also stamps a database that was
 * created before the migrator existed: SQLite runs its in-place upgrades, and MySQL and PostgreSQL create what is missing.
 * The DDL itself stays in the three schema classes (SQLite is the reference, the others follow it).
 */
final class CreateBaselineSchema extends SqlMigration
{
    public function id(): string
    {
        return '0001';
    }

    public function description(): string
    {
        return 'Baseline schema';
    }

    public function up(Connection $db): void
    {
        match ($db->dialect->name()) {
            'sqlite' => (new SqliteSchema($db->pdo))->install(),
            'mysql' => (new MysqlSchema($db->pdo))->install(),
            'pgsql' => (new PostgresSchema($db->pdo))->install(),
        };
    }
}
