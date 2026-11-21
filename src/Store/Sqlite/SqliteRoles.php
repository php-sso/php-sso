<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/**
 * Roles, and who has them. A role is only a name: the app decides what it allows. It is defined once, given to whole
 * groups or to single users, and a user has it when either applies. A role is part of the `roles` claim of every app
 * (`all_clients`) or only of the apps it was made available in.
 *
 * @phpstan-type RoleRow array{id: int, name: string, description: string, all_clients: bool, client_count: int, group_count: int, user_count: int}
 */
final class SqliteRoles implements \PhpSso\Store\Roles
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    private const COLUMNS = 'r.id, r.name, r.description, r.all_clients,
        (SELECT COUNT(*) FROM role_client c WHERE c.role_id = r.id) AS client_count,
        (SELECT COUNT(*) FROM role_group g WHERE g.role_id = r.id) AS group_count,
        (SELECT COUNT(*) FROM role_user u WHERE u.role_id = r.id) AS user_count';

    /**
     * Every role, by name, each with how many clients, groups and users it was given to.
     *
     * @return list<RoleRow>
     */
    public function all(): array
    {
        return array_values(array_map(self::role(...), $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM role r ORDER BY r.name')->fetchAll()));
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM role')->fetchColumn();
    }

    /**
     * The roles a client receives: those for every client and those made available in it.
     *
     * @return list<RoleRow>
     */
    public function forClient(string $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM role r
            WHERE r.all_clients = 1 OR EXISTS (SELECT 1 FROM role_client c WHERE c.role_id = r.id AND c.client_id = ?)
            ORDER BY r.name');
        $stmt->execute([$clientId]);

        return array_values(array_map(self::role(...), $stmt->fetchAll()));
    }

    /** @return RoleRow|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM role r WHERE r.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::role($row);
    }

    /** @return RoleRow|null */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM role r WHERE r.name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        return $row === false ? null : self::role($row);
    }

    /**
     * @param list<string> $clientIds the clients that receive it, when it is not for every client
     *
     * @throws \PDOException when there is already a role with that name (case does not matter)
     */
    public function create(string $name, string $description, bool $allClients = true, array $clientIds = []): int
    {
        $this->pdo->prepare('INSERT INTO role (name, description, all_clients) VALUES (?, ?, ?)')->execute([$name, $description, (int) $allClients]);
        $id = (int) $this->pdo->lastInsertId();
        $this->setClients($id, $allClients, $clientIds);

        return $id;
    }

    /** @throws \PDOException when there is already another role with that name */
    public function update(int $id, string $name, string $description): void
    {
        $this->pdo->prepare('UPDATE role SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
    }

    /**
     * Where the role is available: every client, or exactly these. The list is kept when switching to every client,
     * so switching back remembers it.
     *
     * @param list<string> $clientIds
     */
    public function setClients(int $id, bool $allClients, array $clientIds): void
    {
        $this->pdo->prepare('UPDATE role SET all_clients = ? WHERE id = ?')->execute([(int) $allClients, $id]);
        if ($allClients && $clientIds === []) {
            return;
        }
        $this->pdo->prepare('DELETE FROM role_client WHERE role_id = ?')->execute([$id]);
        foreach (array_unique($clientIds) as $clientId) {
            $this->pdo->prepare('INSERT OR IGNORE INTO role_client (role_id, client_id) VALUES (?, ?)')->execute([$id, $clientId]);
        }
    }

    /** @return list<string> the client ids the role is made available in, whether or not it is for every client */
    public function clientIds(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT client_id FROM role_client WHERE role_id = ? ORDER BY client_id');
        $stmt->execute([$id]);

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** Whoever had the role loses it (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM role WHERE id = ?')->execute([$id]);
    }

    /** @return RoleRow */
    private static function role(mixed $row): array
    {
        \assert(is_array($row) && is_int($row['id']) && is_string($row['name']) && is_string($row['description']) && is_int($row['all_clients'])
            && is_int($row['client_count']) && is_int($row['group_count']) && is_int($row['user_count']));

        return [
            'id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'all_clients' => $row['all_clients'] === 1,
            'client_count' => $row['client_count'], 'group_count' => $row['group_count'], 'user_count' => $row['user_count'],
        ];
    }

    /** Everyone in the group has the role. Does nothing when they already do. */
    public function assignGroup(int $roleId, int $groupId): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO role_group (role_id, group_id) VALUES (?, ?)')->execute([$roleId, $groupId]);
    }

    /** This user has the role, whatever groups they are in. Does nothing when they already do. */
    public function assignUser(int $roleId, string $sub): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO role_user (role_id, user_sub) VALUES (?, ?)')->execute([$roleId, $sub]);
    }

    /**
     * The roles a user has in a client: the roles that client receives, given to the user directly or to a group they
     * are in, each name once.
     *
     * @return list<string>
     */
    public function effectiveFor(string $sub, string $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT r.name FROM role r
            WHERE (r.all_clients = 1 OR EXISTS (SELECT 1 FROM role_client c WHERE c.role_id = r.id AND c.client_id = :c))
              AND (
                EXISTS (SELECT 1 FROM role_user ru WHERE ru.role_id = r.id AND ru.user_sub = :u)
                OR EXISTS (SELECT 1 FROM role_group rg
                    JOIN user_group_member m ON m.group_id = rg.group_id
                    WHERE rg.role_id = r.id AND m.user_sub = :u))
            ORDER BY r.name');
        $stmt->execute([':c' => $clientId, ':u' => $sub]);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            \assert(is_string($name));
            $names[] = $name;
        }

        return $names;
    }
}
