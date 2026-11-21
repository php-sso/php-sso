<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SessionLinks;

use PhpSso\Store\Sql\Connection;
use PhpSso\OAuth\SessionLinks;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(SessionLinks::class, 'terminate')]
final class TerminateTest extends TestCase
{
    public function testItOnlyMarksTheSessionEndedBecauseTheOAuthServerDoesTheRest(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new SqlSessions($db, 3600);
        $told = 0;
        $sessions->onEnded(static function () use (&$told): void {
            ++$told;
        });
        $id = $sessions->start('u1', '203.0.113.1', 'test');

        // Act
        (new SessionLinks($sessions))->terminate($id);

        // Assert
        self::assertFalse($sessions->touch($id, 'u1'));
        self::assertSame(0, $told);
    }
}
