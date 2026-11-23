<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Support;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migration;
use PhpSso\Store\Sql\Migrations\Migrator;

/**
 * For a test of the Migrator on the database under test without touching the real `schema_migrations` or tables: the
 * versions go to a table of their own and the tables the fake migrations make are named `mig_probe_*`. Both are dropped
 * after every test.
 */
trait UsesMigrationSandbox
{
    private const SANDBOX_TABLE = 'schema_migrations_sandbox';

    private Connection $db;

    protected function setUpSandbox(): void
    {
        $this->db = TestConnection::open();
        $this->dropSandbox();
    }

    protected function tearDownSandbox(): void
    {
        $this->dropSandbox();
    }

    /** @param list<Migration> $migrations */
    private function migrator(array $migrations): Migrator
    {
        return new Migrator($this->db, $migrations, self::SANDBOX_TABLE);
    }

    /** @return list<string> */
    private function recordedVersions(): array
    {
        if (!$this->db->tableExists(self::SANDBOX_TABLE)) {
            return [];
        }

        return $this->db->pdo->query('SELECT version FROM "' . self::SANDBOX_TABLE . '" ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function dropSandbox(): void
    {
        $this->db->pdo->exec('DROP TABLE IF EXISTS "' . self::SANDBOX_TABLE . '"');
        foreach (['mig_probe_a', 'mig_probe_b', 'mig_probe_child', 'mig_probe_parent'] as $table) {
            $this->db->pdo->exec('DROP TABLE IF EXISTS "' . $table . '"');
        }
    }
}
