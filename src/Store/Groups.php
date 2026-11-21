<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

final class Groups
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** All groups, each with a `members` count. */
    public function all(): array
    {
        return $this->pdo->query('SELECT g.*, (SELECT COUNT(*) FROM user_group_member m WHERE m.group_id = g.id) AS members
            FROM user_group g ORDER BY g.name')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_group WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_group WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->fetch() ?: null;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM user_group')->fetchColumn();
    }

    public function create(string $name, string $description): int
    {
        $this->pdo->prepare('INSERT INTO user_group (name, description) VALUES (?, ?)')->execute([$name, $description]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $description): void
    {
        $this->pdo->prepare('UPDATE user_group SET name = ?, description = ? WHERE id = ?')->execute([$name, $description, $id]);
    }

    /** Memberships are removed with it (ON DELETE CASCADE). */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM user_group WHERE id = ?')->execute([$id]);
    }

    /** @return array<int, array{sub: string, username: string}> */
    /** LIKE pattern for a user's search text, with wildcards in the input made literal. */
    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    /** Groups whose name or description contains the text, with member counts; for the admin search. */
    public function search(string $q, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT g.id, g.name, g.description, (SELECT COUNT(*) FROM user_group_member m WHERE m.group_id = g.id) AS members
            FROM user_group g WHERE g.name LIKE :q ESCAPE \'\\\' OR g.description LIKE :q ESCAPE \'\\\'
            ORDER BY g.name COLLATE NOCASE LIMIT :l');
        $stmt->bindValue(':q', self::like($q));
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
