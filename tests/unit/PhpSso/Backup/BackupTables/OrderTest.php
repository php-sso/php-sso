<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Backup\BackupTables;

use PDO;
use PhpSso\Backup\BackupTables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversClass(BackupTables::class)]
final class OrderTest extends TestCase
{
    public function testItListsEveryTableOfTheSchemaAndNothingElse(): void
    {
        // Arrange
        $db = TestConnection::open();
        $tables = match ($db->dialect->name()) {
            'sqlite' => $db->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN),
            'mysql' => $db->pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN),
            default => $db->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),
        };

        // Act
        $actual = array_values(array_diff(array_map('strtolower', array_map('strval', $tables)), BackupTables::NOT_DATA));

        // Assert
        $expected = BackupTables::ORDER;
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    public function testNoTableIsListedTwice(): void
    {
        // Arrange
        $order = BackupTables::ORDER;

        // Act
        $unique = array_unique($order);

        // Assert
        $this->assertCount(count($order), $unique);
    }
}
