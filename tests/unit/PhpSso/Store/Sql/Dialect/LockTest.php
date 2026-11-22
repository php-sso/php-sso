<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Dialect;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\MysqlDialect;
use PhpSso\Store\Sql\PostgresDialect;
use PhpSso\Store\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;
use Throwable;

/** One process at a time migrates: MySQL and PostgreSQL with a named lock, SQLite with the write transaction a migration opens. */
#[CoversMethod(MysqlDialect::class, 'lock')]
#[CoversMethod(MysqlDialect::class, 'unlock')]
#[CoversMethod(PostgresDialect::class, 'lock')]
#[CoversMethod(PostgresDialect::class, 'unlock')]
#[CoversMethod(SqliteDialect::class, 'beginMigration')]
#[CoversMethod(SqliteDialect::class, 'rollbackMigration')]
final class LockTest extends TestCase
{
    /** @var list<callable> */
    private array $cleanups = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanups as $cleanup) {
            $cleanup();
        }
    }

    /** @return array{Connection, Connection} */
    private function pair(): array
    {
        [$first, $second, $cleanup] = TestConnection::openPair();
        $this->cleanups[] = $cleanup;

        return [$first, $second];
    }

    private function acquire(Connection $db, int $seconds): void
    {
        $db->dialect->name() === 'sqlite' ? $db->dialect->beginMigration($db->pdo) : $db->dialect->lock($db->pdo, $seconds);
    }

    private function release(Connection $db): void
    {
        $db->dialect->name() === 'sqlite' ? $db->dialect->rollbackMigration($db->pdo) : $db->dialect->unlock($db->pdo);
    }

    public function testASecondProcessCannotTakeTheLockWhileItIsHeld(): void
    {
        // Arrange
        [$first, $second] = $this->pair();
        $this->acquire($first, 1);

        // Act
        $failure = null;
        try {
            $this->acquire($second, 1);
        } catch (Throwable $e) {
            $failure = $e;
        } finally {
            $this->release($first);
        }

        // Assert
        $this->assertNotNull($failure);
    }

    public function testTheLockCanBeTakenAgainOnceItIsReleased(): void
    {
        // Arrange
        [$first, $second] = $this->pair();
        $this->acquire($first, 1);
        $this->release($first);

        // Act
        $this->acquire($second, 1);
        $this->release($second);

        // Assert
        $this->addToAssertionCount(1); // taking it did not throw
    }

    public function testSqliteKeepsForeignKeysOnAfterTheMigrationIsRolledBack(): void
    {
        // Arrange
        [$first] = $this->pair();
        if ($first->dialect->name() !== 'sqlite') {
            $this->markTestSkipped('Foreign keys are a SQLite pragma.');
        }
        $first->dialect->beginMigration($first->pdo);

        // Act
        $first->dialect->rollbackMigration($first->pdo);

        // Assert
        $this->assertSame(1, (int) $first->pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertFalse($first->pdo->inTransaction());
    }
}
