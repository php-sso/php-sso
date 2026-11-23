<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\StorageSchema;

use PDO;
use PhpSso\Store\Sql\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

/** The SQLite schema is the reference: every other database must have the same tables and columns (run with SSO_TEST_DATABASE set to see it). */
#[CoversClass(Connection::class)]
final class SameTablesAsSqliteTest extends TestCase
{
    public function testTheDatabaseUnderTestHasTheTablesAndColumnsSqliteHas(): void
    {
        // Arrange
        $reference = $this->columns(Connection::sqlite(':memory:'));

        // Act
        $actual = $this->columns(TestConnection::open());

        // Assert
        $this->assertSame($reference, $actual);
    }

    /** @return array<string, list<string>> table => its columns, both sorted */
    private function columns(Connection $connection): array
    {
        $pdo = $connection->pdo;
        $tables = match ($connection->dialect->name()) {
            'sqlite' => $pdo->query("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN),
            'mysql' => $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN),
            default => $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),
        };
        $result = [];
        foreach ($tables as $table) {
            $table = strtolower((string) $table);
            $columns = match ($connection->dialect->name()) {
                'sqlite' => array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name'),
                'mysql' => $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetchAll(PDO::FETCH_COLUMN),
                default => $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '{$table}'")->fetchAll(PDO::FETCH_COLUMN),
            };
            $columns = array_map('strtolower', array_map('strval', $columns));
            sort($columns);
            $result[$table] = $columns;
        }
        ksort($result);

        return $result;
    }
}
