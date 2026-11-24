<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\Dialect;

use PDO;
use PhpSso\Store\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversMethod(SqliteDialect::class, 'commitMigration')]
final class CommitMigrationTest extends TestCase
{
    private function database(bool $withOrphan): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parents(id))');
        if ($withOrphan) {
            $pdo->exec('INSERT INTO children (id, parent_id) VALUES (1, 99)'); // foreign keys are off on a fresh connection
        }

        return $pdo;
    }

    public function testAnOrphanThatWasThereBeforeDoesNotStopAMigration(): void
    {
        // Arrange
        $pdo = $this->database(withOrphan: true);
        $dialect = new SqliteDialect();
        $dialect->beginMigration($pdo);
        $pdo->exec('ALTER TABLE parents ADD COLUMN note TEXT');

        // Act
        $dialect->commitMigration($pdo);

        // Assert
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame('note', $pdo->query('SELECT name FROM pragma_table_info("parents") WHERE name = "note"')->fetchColumn());
    }

    public function testAnOrphanTheMigrationCreatesIsRefusedAndRolledBack(): void
    {
        // Arrange
        $pdo = $this->database(withOrphan: false);
        $dialect = new SqliteDialect();
        $dialect->beginMigration($pdo);
        $pdo->exec('INSERT INTO children (id, parent_id) VALUES (1, 99)');

        // Act
        try {
            $dialect->commitMigration($pdo);
            $this->fail('Expected the migration to be refused.');
        } catch (RuntimeException $e) {
            // Assert
            $this->assertStringContainsString('broke a foreign key', $e->getMessage());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM children')->fetchColumn());
        }
    }

    public function testAnotherOrphanNextToAnOldOneIsRefused(): void
    {
        // Arrange
        $pdo = $this->database(withOrphan: true);
        $dialect = new SqliteDialect();
        $dialect->beginMigration($pdo);
        $pdo->exec('INSERT INTO children (id, parent_id) VALUES (2, 98)');
        $this->expectException(RuntimeException::class);

        // Act
        $dialect->commitMigration($pdo);
    }
}
