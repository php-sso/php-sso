<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sessions;

use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(Sessions::class, 'amr')]
#[CoversMethod(Sessions::class, 'start')]
final class AmrTest extends TestCase
{
    private function sessions(): Sessions
    {
        $db = TestConnection::open();
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");

        return new SqlSessions($db, 3600);
    }

    public function testASessionRemembersHowThePersonSignedIn(): void
    {
        // Arrange
        $sessions = $this->sessions();

        // Act
        $id = $sessions->start('u1', '203.0.113.1', 'test', null, ['pwd', 'otp', 'mfa']);

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $sessions->amr($id));
    }

    public function testItIsNullWhenNothingWasReported(): void
    {
        // Arrange
        $sessions = $this->sessions();

        // Act
        $id = $sessions->start('u1', '203.0.113.1', 'test');

        // Assert
        self::assertNull($sessions->amr($id));
    }

    public function testAnEmptyListCountsAsNothingReported(): void
    {
        // Arrange
        $sessions = $this->sessions();

        // Act
        $id = $sessions->start('u1', '203.0.113.1', 'test', null, []);

        // Assert
        self::assertNull($sessions->amr($id));
    }

    public function testASessionAnAdministratorStartedForAnotherPersonSaysNothingAboutHowTheyAuthenticated(): void
    {
        // Arrange
        $sessions = $this->sessions();

        // Act
        $id = $sessions->start('u1', '203.0.113.1', 'test', 'admin-sub');

        // Assert
        self::assertNull($sessions->amr($id));
    }

    public function testAnUnknownSessionHasNone(): void
    {
        // Arrange
        $sessions = $this->sessions();

        // Act
        $amr = $sessions->amr('nope');

        // Assert
        self::assertNull($amr);
    }
}
