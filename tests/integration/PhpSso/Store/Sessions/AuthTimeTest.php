<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sessions;

use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(Sessions::class, 'authTime')]
final class AuthTimeTest extends TestCase
{
    public function testItIsWhenTheSessionStartedBecauseASessionStartsWithALogin(): void
    {
        // Arrange
        $db = TestConnection::open();
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new SqlSessions($db, 3600);
        $before = time();

        // Act
        $id = $sessions->start('u1', '203.0.113.1', 'test');
        $authTime = $sessions->authTime($id);

        // Assert
        self::assertGreaterThanOrEqual($before, $authTime);
        self::assertLessThanOrEqual(time(), $authTime);
    }

    public function testItIsNullForAnUnknownSession(): void
    {
        // Arrange
        $sessions = new SqlSessions(TestConnection::open(), 3600);

        // Act
        $authTime = $sessions->authTime('nope');

        // Assert
        self::assertNull($authTime);
    }
}
