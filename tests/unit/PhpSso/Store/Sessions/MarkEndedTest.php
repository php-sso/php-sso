<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sessions;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(Sessions::class, 'markEnded')]
final class MarkEndedTest extends TestCase
{
    public function testItEndsTheSessionWithoutTellingTheListener(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new SqlSessions($db, 3600);
        $told = [];
        $sessions->onEnded(static function (array $ended) use (&$told): void {
            $told[] = $ended;
        });
        $id = $sessions->start('u1', '203.0.113.1', 'test');

        // Act
        $sessions->markEnded($id);

        // Assert
        self::assertFalse($sessions->touch($id, 'u1'));
        self::assertSame([], $told);
    }
}
