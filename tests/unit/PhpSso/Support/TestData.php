<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Clients;
use PhpSso\Store\Groups;
use PhpSso\Store\Roles;
use PhpSso\Store\Users;
use PhpSso\Store\Sql\SqlClients;
use PhpSso\Store\Sql\SqlGroups;
use PhpSso\Store\Sql\SqlRoles;
use PhpSso\Store\Sql\SqlUsers;

/** Builds people, groups, clients and roles in an in-memory database for tests. */
final class TestData
{
    public readonly Connection $db;
    public readonly Users $users;
    public readonly Groups $groups;
    public readonly Clients $clients;
    public readonly Roles $roles;

    public function __construct()
    {
        $this->db = TestConnection::open();
        $this->users = new SqlUsers($this->db);
        $this->groups = new SqlGroups($this->db);
        $this->clients = new SqlClients($this->db);
        $this->roles = new SqlRoles($this->db);
    }

    /** A group with the given people in it. @param string[] $subs */
    public function group(string $name, array $subs = []): int
    {
        $id = $this->groups->create($name, '');
        foreach ($subs as $sub) {
            $this->db->pdo->prepare('INSERT INTO user_group_member (user_sub, group_id) VALUES (?, ?)')->execute([$sub, $id]);
        }

        return $id;
    }

    public function user(string $username): string
    {
        return $this->users->create(['username' => $username, 'password' => 'secret-pass', 'name' => ucfirst($username), 'email' => $username . '@example.com', 'email_verified' => true, 'is_admin' => false]);
    }

    public function client(string $clientId, bool $restricted = false): void
    {
        $this->clients->insert(['client_id' => $clientId, 'name' => 'App ' . $clientId, 'secret_hash' => null, 'confidential' => false,
            'redirect_uris' => ['http://localhost/cb'], 'grants' => ['authorization_code'], 'backchannel_logout_uri' => null, 'post_logout_redirect_uris' => []]);
        $this->clients->setRestricted($clientId, $restricted);
    }

    public function linkUser(string $clientId, string $sub): void
    {
        $this->db->pdo->prepare('INSERT INTO client_user (client_id, user_sub) VALUES (?, ?)')->execute([$clientId, $sub]);
    }

    public function linkGroup(string $clientId, string $sub, string $groupName): void
    {
        $id = $this->groups->create($groupName, '');
        $this->users->setGroups($sub, [$id]);
        $this->db->pdo->prepare('INSERT INTO client_group (client_id, group_id) VALUES (?, ?)')->execute([$clientId, $id]);
    }
}
