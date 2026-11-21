<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/** Which people a SCIM client provisioned, with that client's own identifiers for them. */
final class ScimUsers
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** The person (user row plus `user_name`, `external_id`, `given_name`, `family_name`) if this client manages them. */
    public function find(int $clientId, string $sub): ?array
    {
        return $this->one('u.sub = ?', [$clientId, $sub]);
    }

    public function findByUserName(int $clientId, string $userName): ?array
    {
        return $this->one('s.user_name = ? COLLATE NOCASE', [$clientId, $userName]);
    }

    public function findByExternalId(int $clientId, string $externalId): ?array
    {
        return $this->one('s.external_id = ?', [$clientId, $externalId]);
    }

    public function findByEmail(int $clientId, string $email): ?array
    {
        return $this->one('u.email = ? COLLATE NOCASE', [$clientId, $email]);
    }

    /**
     * One page of the people this client manages.
     *
     * @param array{attr: string, value: string}|null $filter `userName`, `externalId`, `email` or `id` equal to a value
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function list(int $clientId, ?array $filter, int $start, int $count): array
    {
        $where = 's.client_id = ?';
        $args = [$clientId];
        if ($filter !== null) {
            $column = ['userName' => 's.user_name', 'externalId' => 's.external_id', 'email' => 'u.email', 'id' => 'u.sub'][$filter['attr']] ?? null;
            if ($column === null) {
                return ['total' => 0, 'rows' => []];
            }
            $where .= " AND {$column} = ?" . ($filter['attr'] === 'externalId' || $filter['attr'] === 'id' ? '' : ' COLLATE NOCASE');
            $args[] = $filter['value'];
        }
        $total = $this->pdo->prepare("SELECT COUNT(*) FROM scim_users s JOIN users u ON u.sub = s.user_sub WHERE {$where}");
        $total->execute($args);

        $stmt = $this->pdo->prepare("SELECT u.*, s.client_id, s.user_name, s.external_id, s.given_name, s.family_name FROM scim_users s JOIN users u ON u.sub = s.user_sub WHERE {$where} ORDER BY u.username LIMIT ? OFFSET ?");
        foreach ($args as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }
        $stmt->bindValue(count($args) + 1, $count, PDO::PARAM_INT);
        $stmt->bindValue(count($args) + 2, max(0, $start - 1), PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => (int) $total->fetchColumn(), 'rows' => $stmt->fetchAll()];
    }

    public function attach(int $clientId, string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        $this->pdo->prepare('INSERT INTO scim_users (user_sub, client_id, user_name, external_id, given_name, family_name) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$sub, $clientId, $userName, $externalId, $givenName, $familyName]);
    }

    public function update(string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void
    {
        $this->pdo->prepare('UPDATE scim_users SET user_name = ?, external_id = ?, given_name = ?, family_name = ? WHERE user_sub = ?')
            ->execute([$userName, $externalId, $givenName, $familyName, $sub]);
    }

    /** Which client manages this person, as `id` and `name`, or null. @return array{id: int, name: string}|null */
    public function managedBy(string $sub): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.id, c.name FROM scim_users s JOIN scim_clients c ON c.id = s.client_id WHERE s.user_sub = ?');
        $stmt->execute([$sub]);
        $row = $stmt->fetch();

        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /** @param list<mixed> $args after the client id */
    private function one(string $condition, array $args): ?array
    {
        $stmt = $this->pdo->prepare("SELECT u.*, s.client_id, s.user_name, s.external_id, s.given_name, s.family_name
            FROM scim_users s JOIN users u ON u.sub = s.user_sub WHERE s.client_id = ? AND {$condition} LIMIT 1");
        $stmt->execute($args);

        return $stmt->fetch() ?: null;
    }
}
