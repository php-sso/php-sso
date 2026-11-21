<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sessions;

use PhpSso\Database;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Sessions::class, 'authTime')]
final class AuthTimeTest extends TestCase
{
    public function testItIsWhenTheSessionStartedBecauseASessionStartsWithALogin(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $sessions = new Sessions($db, 3600);
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
        $sessions = new Sessions(new Database(':memory:'), 3600);

        // Act
        $authTime = $sessions->authTime('nope');

        // Assert
        self::assertNull($authTime);
    }
}
