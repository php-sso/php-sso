<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Migrations\Migrator;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migrator;
use PhpSso\Store\Sql\Migrations\Migration;
use PhpSso\Store\Sql\Migrations\Migrations;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\CountingConnection;

/** The app's own migrations. */
#[CoversMethod(Migrator::class, 'migrate')]
final class MigrateRealSchemaTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sso-migrate-test-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    public function testANewSqliteDatabaseGetsTheSchemaAndTheBaselineIsRecorded(): void
    {
        // Arrange (no database yet)

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $this->assertTrue($db->tableExists('users'));
        $this->assertTrue($db->tableExists('schema_migrations'));
        $this->assertSame(array_map(static fn (Migration $m): string => $m->id(), Migrations::all()), array_keys($db->migrator()->applied()));
    }

    public function testASqliteDatabaseFromBeforeTheMigratorIsUpgradedAndStamped(): void
    {
        // Arrange (a database of the era before schema_migrations: a users table that requires a password, and a person in it)
        $old = new \PDO('sqlite:' . $this->path);
        $old->exec('CREATE TABLE users (
            sub TEXT PRIMARY KEY, username TEXT NOT NULL UNIQUE COLLATE NOCASE, password_hash TEXT NOT NULL,
            name TEXT NOT NULL, email TEXT NOT NULL, email_verified INTEGER NOT NULL DEFAULT 0, is_admin INTEGER NOT NULL DEFAULT 0)');
        $old->exec("INSERT INTO users VALUES ('sub-1', 'alice', 'hash-1', 'Alice', 'alice@example.com', 1, 1)");
        unset($old);

        // Act
        $db = Connection::sqlite($this->path);

        // Assert
        $this->assertSame(array_map(static fn (Migration $m): string => $m->id(), Migrations::all()), array_keys($db->migrator()->applied()));
        $this->assertSame('alice', $db->pdo->query('SELECT username FROM users')->fetchColumn());
        $this->assertTrue($db->columnExists('users', 'must_change_password'));
        $this->assertTrue($db->tableExists('oauth_codes'));
    }

    public function testConnectingAgainToTheSameSqliteDatabaseChangesNothing(): void
    {
        // Arrange
        $first = Connection::sqlite($this->path);
        $appliedAt = $first->migrator()->applied();

        // Act
        $second = Connection::sqlite($this->path);

        // Assert
        $this->assertSame($appliedAt, $second->migrator()->applied());
    }

    public function testAnUpToDateDatabaseCostsOneSelectAndNoDdl(): void
    {
        // Arrange
        $db = CountingConnection::open();
        (new Migrator($db))->migrate(); // the database under test has the schema already; a new in-memory SQLite gets it now
        $db->pdo->statements = [];

        // Act
        $ran = (new Migrator($db))->migrate();

        // Assert
        $this->assertSame([], $ran);
        $this->assertSame(['SELECT version, applied_at FROM "schema_migrations"'], $db->pdo->statements);
    }

    public function testADatabaseOpenedWithoutMigratingIsLeftPending(): void
    {
        // Arrange (a connection that does not migrate)
        $db = Connection::sqlite($this->path, migrate: false);

        // Act
        $pending = array_map(static fn ($m): string => $m->id(), $db->migrator()->pending());

        // Assert
        $this->assertSame(array_map(static fn (Migration $m): string => $m->id(), Migrations::all()), $pending);
        $this->assertFalse($db->tableExists('users'));
    }
}
