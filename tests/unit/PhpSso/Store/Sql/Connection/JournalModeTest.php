<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Connection;

use PhpSso\Store\Sql\Connection;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Connection::class, 'sqlite')]
final class JournalModeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sso-wal-test-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->path . '*') ?: []);
    }

    public function testAFileDatabaseUsesTheWriteAheadLogAndWaitsForALockedDatabase(): void
    {
        // Arrange
        $connection = Connection::sqlite($this->path);

        // Act
        $mode = $connection->pdo->query('PRAGMA journal_mode')->fetchColumn();
        $wait = $connection->pdo->query('PRAGMA busy_timeout')->fetchColumn();

        // Assert
        $this->assertSame('wal', $mode);
        $this->assertSame(5000, (int) $wait);
    }
}
