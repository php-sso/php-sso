<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/** Which groups a SCIM client provisioned, with that client's own name and id for them, and who is in them. */
final class SqliteScimGroups implements \PhpSso\Store\ScimGroups
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** The group (group row plus `client_id`, `display_name`, `external_id`) if this client manages it. */
    public function find(int $clientId, int $groupId): ?array
    {
        return $this->one('g.id = ?', [$clientId, $groupId]);
    }

    public function findByDisplayName(int $clientId, string $displayName): ?array
    {
        return $this->one('s.display_name = ? COLLATE NOCASE', [$clientId, $displayName]);
    }

    public function findByExternalId(int $clientId, string $externalId): ?array
    {
        return $this->one('s.external_id = ?', [$clientId, $externalId]);
    }

    /**
     * One page of the groups this client manages.
     *
     * @param array{attr: string, value: string}|null $filter `displayName`, `externalId` or `id` equal to a value
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function list(int $clientId, ?array $filter, int $start, int $count): array
    {
        $where = 's.client_id = ?';
        $args = [$clientId];
        if ($filter !== null) {
            $column = ['displayName' => 's.display_name', 'externalId' => 's.external_id', 'id' => 'g.id'][$filter['attr']] ?? null;
            if ($column === null) {
                return ['total' => 0, 'rows' => []];
            }
            $where .= " AND {$column} = ?" . ($filter['attr'] === 'displayName' ? ' COLLATE NOCASE' : '');
            $args[] = $filter['attr'] === 'id' ? (int) $filter['value'] : $filter['value'];
        }
        $total = $this->pdo->prepare("SELECT COUNT(*) FROM scim_groups s JOIN user_group g ON g.id = s.group_id WHERE {$where}");
        $total->execute($args);

        $stmt = $this->pdo->prepare("SELECT g.*, s.client_id, s.display_name, s.external_id FROM scim_groups s JOIN user_group g ON g.id = s.group_id WHERE {$where} ORDER BY g.name LIMIT ? OFFSET ?");
        foreach ($args as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($args) + 1, $count, PDO::PARAM_INT);
        $stmt->bindValue(count($args) + 2, max(0, $start - 1), PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => (int) $total->fetchColumn(), 'rows' => $stmt->fetchAll()];
    }

    public function attach(int $clientId, int $groupId, string $displayName, ?string $externalId): void
    {
        $this->pdo->prepare('INSERT INTO scim_groups (group_id, client_id, display_name, external_id) VALUES (?, ?, ?, ?)')
            ->execute([$groupId, $clientId, $displayName, $externalId]);
    }

    public function update(int $groupId, string $displayName, ?string $externalId): void
    {
        $this->pdo->prepare('UPDATE scim_groups SET display_name = ?, external_id = ? WHERE group_id = ?')->execute([$displayName, $externalId, $groupId]);
    }

    /** Which client manages this group, as `id` and `name`, or null. @return array{id: int, name: string}|null */
    public function managedBy(int $groupId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.id, c.name FROM scim_groups s JOIN scim_clients c ON c.id = s.client_id WHERE s.group_id = ?');
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();

        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /** @return list<string> the `sub` of everyone in the group */
    public function members(int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT m.user_sub FROM user_group_member m JOIN users u ON u.sub = m.user_sub WHERE m.group_id = ? ORDER BY u.username');
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<string> $subs */
    public function addMembers(int $groupId, array $subs): void
    {
        $insert = $this->pdo->prepare('INSERT OR IGNORE INTO user_group_member (user_sub, group_id) VALUES (?, ?)');
        foreach (array_unique($subs) as $sub) {
            $insert->execute([$sub, $groupId]);
        }
    }

    /** @param list<string> $subs */
    public function removeMembers(int $groupId, array $subs): void
    {
        $delete = $this->pdo->prepare('DELETE FROM user_group_member WHERE group_id = ? AND user_sub = ?');
        foreach (array_unique($subs) as $sub) {
            $delete->execute([$groupId, $sub]);
        }
    }

    /** Makes exactly these people the members. @param list<string> $subs */
    public function replaceMembers(int $groupId, array $subs): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM user_group_member WHERE group_id = ?')->execute([$groupId]);
        $this->addMembers($groupId, $subs);
        $this->pdo->commit();
    }

    /** @param list<mixed> $args after the client id */
    private function one(string $condition, array $args): ?array
    {
        $stmt = $this->pdo->prepare("SELECT g.*, s.client_id, s.display_name, s.external_id
            FROM scim_groups s JOIN user_group g ON g.id = s.group_id WHERE s.client_id = ? AND {$condition} LIMIT 1");
        $stmt->execute($args);

        return $stmt->fetch() ?: null;
    }
}
