<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SessionLinks;

use PhpSso\OAuth\SessionLinks;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlSessions;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SessionLinks::class, 'register')]
final class RegisterTest extends TestCase
{
    public function testItRemembersWhichClientsTookPartInASession(): void
    {
        // Arrange
        $db = TestConnection::open();
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('u1', 'alice', 'x', 'Alice', 'a@example.com')");
        $db->pdo->exec("INSERT INTO clients (client_id, name, confidential, redirect_uris, grants) VALUES ('web', 'Web', 1, '[]', '[]'), ('spa', 'Spa', 0, '[]', '[]')");
        $sessions = new SqlSessions($db, 3600);
        $links = new SessionLinks($sessions);
        $id = $sessions->start('u1', '203.0.113.1', 'test');

        // Act
        $links->register($id, 'web');
        $links->register($id, 'spa');
        $links->register($id, 'web');

        // Assert
        self::assertEqualsCanonicalizing(['web', 'spa'], $links->clientsOf($id));
    }
}
