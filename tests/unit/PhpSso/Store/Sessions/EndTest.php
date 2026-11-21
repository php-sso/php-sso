<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sessions;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sqlite\SqliteSessions;

#[CoversMethod(Sessions::class, 'end')]
final class EndTest extends TestCase
{
    public function testItEndsTheSessionAndTellsTheListenerWhichOneEnded(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new SqliteSessions($db, 3600);
        $told = [];
        $sessions->onEnded(static function (array $ended) use (&$told): void {
            $told = $ended;
        });
        $id = $sessions->start('u1', '203.0.113.1', 'test');

        // Act
        $ended = $sessions->end($id);

        // Assert
        self::assertSame(1, $ended);
        self::assertSame([['id' => $id, 'user_sub' => 'u1']], $told);
        self::assertFalse($sessions->touch($id, 'u1'));
    }

    public function testEndingAnAlreadyEndedSessionDoesNothing(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new SqliteSessions($db, 3600);
        $id = $sessions->start('u1', '203.0.113.1', 'test');
        $sessions->end($id);

        // Act
        $again = $sessions->end($id);

        // Assert
        self::assertSame(0, $again);
    }
}
