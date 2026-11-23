<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\Connection;

use PhpSso\Store\Sql\Connection;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Connection::class, 'sqlite')]
final class ConstructTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sso-db-test-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** A database from before passwords became optional, with a person and a session that points at them. */
    private function oldDatabase(): void
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (
            sub TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL,
            name TEXT NOT NULL, email TEXT NOT NULL, email_verified INTEGER NOT NULL DEFAULT 0, is_admin INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES ('sub-1', 'alice', 'hash-1', 'Alice', 'alice@example.com', 1, 1)");
        $pdo->exec('CREATE TABLE login_sessions (
            id TEXT PRIMARY KEY, user_sub TEXT NOT NULL REFERENCES users(sub) ON DELETE CASCADE,
            created_at INTEGER NOT NULL, last_seen INTEGER NOT NULL, ip TEXT, user_agent TEXT, ended_at INTEGER)');
        $pdo->exec("INSERT INTO login_sessions (id, user_sub, created_at, last_seen) VALUES ('s1', 'sub-1', 1, 1)");
    }

    public function testItKeepsPeopleWhenItRebuildsTheUsersTableForOptionalPasswords(): void
    {
        // Arrange
        $this->oldDatabase();

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $user = $db->pdo->query("SELECT * FROM users WHERE sub = 'sub-1'")->fetch();
        $this->assertSame(['alice', 'hash-1', 'Alice', 'alice@example.com', 1, 1], [$user['username'], $user['password_hash'], $user['name'], $user['email'], (int) $user['email_verified'], (int) $user['is_admin']]);
    }

    public function testItAcceptsAPersonWithoutAPasswordAfterTheRebuild(): void
    {
        // Arrange
        $this->oldDatabase();
        $db = Connection::sqlite($this->path);

        // Act
        $db->pdo->exec("INSERT INTO users (sub, username, password_hash, name, email) VALUES ('sub-2', 'bob', NULL, 'Bob', 'bob@example.com')");

        // Assert
        $this->assertNull($db->pdo->query("SELECT password_hash FROM users WHERE sub = 'sub-2'")->fetchColumn());
    }

    public function testRowsThatPointAtUsersStillCascadeAfterTheRebuild(): void
    {
        // Arrange
        $this->oldDatabase();
        $db = Connection::sqlite($this->path);

        // Act
        $db->pdo->exec("DELETE FROM users WHERE sub = 'sub-1'");

        // Assert
        $this->assertSame(0, (int) $db->pdo->query('SELECT COUNT(*) FROM login_sessions')->fetchColumn());
    }

    public function testItLeavesAnAlreadyRebuiltDatabaseAlone(): void
    {
        // Arrange
        $this->oldDatabase();
        Connection::sqlite($this->path);

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $this->assertSame('hash-1', $db->pdo->query("SELECT password_hash FROM users WHERE sub = 'sub-1'")->fetchColumn());
    }

    public function testExistingPeopleAreNotRequiredToChooseANewPassword(): void
    {
        // Arrange
        $this->oldDatabase();

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $this->assertSame(0, (int) $db->pdo->query("SELECT must_change_password FROM users WHERE sub = 'sub-1'")->fetchColumn());
    }

    public function testADatabaseWithOptionalPasswordsButNoFlagColumnGetsIt(): void
    {
        // Arrange
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->exec('CREATE TABLE users (
            sub TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT,
            name TEXT NOT NULL, email TEXT NOT NULL, email_verified INTEGER NOT NULL DEFAULT 0, is_admin INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO users VALUES ('sub-1', 'alice', 'hash-1', 'Alice', 'alice@example.com', 1, 1)");
        unset($pdo);

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $this->assertSame(0, (int) $db->pdo->query("SELECT must_change_password FROM users WHERE sub = 'sub-1'")->fetchColumn());
    }
}
