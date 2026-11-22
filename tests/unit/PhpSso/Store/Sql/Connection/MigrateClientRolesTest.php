<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Connection;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Roles;
use PDO;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlRoles;

/** Roles used to belong to one client each. Opening an old database turns them into global roles. */
#[CoversMethod(Connection::class, 'sqlite')]
final class MigrateClientRolesTest extends TestCase
{
    private string $path;
    private PDO $old;
    private string $alice;
    private int $staff;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sso-roles-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = Connection::sqlite($this->path);
        $users = new \PhpSso\Store\Sql\SqlUsers($db);
        $groups = new \PhpSso\Store\Sql\SqlGroups($db);
        $clients = new \PhpSso\Store\Sql\SqlClients($db);
        $this->alice = $users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'a@example.com', 'email_verified' => true, 'is_admin' => false]);
        $this->staff = $groups->create('staff', '');
        foreach (['app', 'other'] as $id) {
            $clients->insert(['client_id' => $id, 'name' => $id, 'secret_hash' => null, 'confidential' => false, 'redirect_uris' => ['http://localhost/cb'],
                'grants' => ['authorization_code'], 'backchannel_logout_uri' => null, 'post_logout_redirect_uris' => []]);
        }
        $this->old = $db->pdo;
        $this->old->exec('PRAGMA foreign_keys = ON');
        $this->old->exec('CREATE TABLE client_role (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id TEXT NOT NULL, name TEXT NOT NULL COLLATE NOCASE, description TEXT NOT NULL DEFAULT \'\')');
        $this->old->exec('CREATE TABLE client_role_group (role_id INTEGER NOT NULL, group_id INTEGER NOT NULL)');
        $this->old->exec('CREATE TABLE client_role_user (role_id INTEGER NOT NULL, user_sub TEXT NOT NULL)');
        $this->old->exec('DROP TABLE schema_migrations'); // a database from before the migrator: the baseline migration runs the upgrade
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function oldRole(string $client, string $name, array $groups = [], array $users = []): void
    {
        $this->old->prepare('INSERT INTO client_role (client_id, name, description) VALUES (?, ?, ?)')->execute([$client, $name, 'old']);
        $id = (int) $this->old->lastInsertId();
        foreach ($groups as $g) {
            $this->old->prepare('INSERT INTO client_role_group VALUES (?, ?)')->execute([$id, $g]);
        }
        foreach ($users as $u) {
            $this->old->prepare('INSERT INTO client_role_user VALUES (?, ?)')->execute([$id, $u]);
        }
    }

    private function roles(): Roles
    {
        return new SqlRoles(Connection::sqlite($this->path));
    }

    public function testARoleOfOneClientBecomesAGlobalRoleAvailableOnlyInThatClient(): void
    {
        // Arrange
        $this->oldRole('app', 'editor', [$this->staff]);

        // Act
        $roles = $this->roles();

        // Assert
        $role = $roles->findByName('editor');
        $this->assertFalse($role['all_clients'] ?? true);
        $this->assertSame(['app'], $roles->clientIds($role['id'] ?? 0));
        $this->assertSame(1, $role['group_count'] ?? 0);
    }

    public function testRolesOfTheSameNameAndHoldersAreMergedIntoOne(): void
    {
        // Arrange
        $this->oldRole('app', 'editor', [$this->staff]);
        $this->oldRole('other', 'editor', [$this->staff]);

        // Act
        $roles = $this->roles();

        // Assert
        $this->assertSame(['editor'], array_column($roles->all(), 'name'));
        $this->assertSame(['app', 'other'], $roles->clientIds($roles->findByName('editor')['id'] ?? 0));
    }

    public function testRolesOfTheSameNameWithOtherHoldersStaySeparateSoNobodyGainsARole(): void
    {
        // Arrange
        $this->oldRole('app', 'editor', [$this->staff]);
        $this->oldRole('other', 'editor', [], [$this->alice]);

        // Act
        $roles = $this->roles();

        // Assert
        $this->assertSame(['editor', 'editor-other'], array_column($roles->all(), 'name'));
        $this->assertSame([], $roles->effectiveFor($this->alice, 'app'));
        $this->assertSame(['editor-other'], $roles->effectiveFor($this->alice, 'other'));
    }

    public function testTheOldTablesAreGoneAndOpeningAgainChangesNothing(): void
    {
        // Arrange
        $this->oldRole('app', 'editor', [$this->staff]);
        $this->roles();

        // Act
        $roles = $this->roles();

        // Assert
        $this->assertSame(['editor'], array_column($roles->all(), 'name'));
        $left = (new PDO('sqlite:' . $this->path))->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'client_role%'")->fetchColumn();
        $this->assertSame(0, (int) $left);
    }
}
