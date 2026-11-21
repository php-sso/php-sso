<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/**
 * A many-to-many link between an owner and the items it contains, with the operations the admin
 * "relation picker" needs: paged listing with a filter, search for people to add, add and remove
 * (one, many, or everything matching a filter).
 *
 *   group-users   owner: a group    items: its members
 *   user-groups   owner: a user     items: the groups they are in
 *   client-groups owner: a client   items: the groups allowed to sign in to it
 *   client-users  owner: a client   items: the people allowed to sign in to it
 *   role-groups   owner: a role     items: the groups that have it
 *   role-users    owner: a role     items: the users that have it directly
 *
 * Items come back as ['id', 'name', 'detail']. Table and column names are fixed in the factories below,
 * never user input; every value is bound.
 */
final class SqliteRelation implements \PhpSso\Store\Relation
{
    private PDO $pdo;

    /**
     * @param string[] $searchColumns columns of the item table matched by the filter and the add search
     */
    private function __construct(
        Database $db,
        private string $junction,
        private string $ownerColumn,
        private string $itemColumn,
        private string $itemTable,
        private string $itemKey,
        private string $itemSelect,
        private array $searchColumns,
    ) {
        $this->pdo = $db->pdo;
    }

    public static function groupUsers(Database $db): self
    {
        return new self($db, 'user_group_member', 'group_id', 'user_sub', 'users', 'sub',
            'i.sub AS id, i.name AS name, i.username || \' · \' || i.email AS detail',
            ['username', 'name', 'email']);
    }

    public static function userGroups(Database $db): self
    {
        return new self($db, 'user_group_member', 'user_sub', 'group_id', 'user_group', 'id',
            'CAST(i.id AS TEXT) AS id, i.name AS name, i.description AS detail',
            ['name', 'description']);
    }

    public static function clientGroups(Database $db): self
    {
        return new self($db, 'client_group', 'client_id', 'group_id', 'user_group', 'id',
            'CAST(i.id AS TEXT) AS id, i.name AS name, i.description AS detail',
            ['name', 'description']);
    }

    public static function clientUsers(Database $db): self
    {
        return new self($db, 'client_user', 'client_id', 'user_sub', 'users', 'sub',
            'i.sub AS id, i.name AS name, i.username || \' · \' || i.email AS detail',
            ['username', 'name', 'email']);
    }

    public static function roleGroups(Database $db): self
    {
        return new self($db, 'role_group', 'role_id', 'group_id', 'user_group', 'id',
            'CAST(i.id AS TEXT) AS id, i.name AS name, i.description AS detail',
            ['name', 'description']);
    }

    public static function roleUsers(Database $db): self
    {
        return new self($db, 'role_user', 'role_id', 'user_sub', 'users', 'sub',
            'i.sub AS id, i.name AS name, i.username || \' · \' || i.email AS detail',
            ['username', 'name', 'email']);
    }

    /** SQL condition: the filter text appears in one of the search columns. Uses the :q parameter. */
    private function matches(): string
    {
        return '(' . implode(' OR ', array_map(fn (string $c) => "i.{$c} LIKE :q ESCAPE '\\'", $this->searchColumns)) . ')';
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    public function count(string|int $owner, string $q): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->itemTable} i
            JOIN {$this->junction} j ON j.{$this->itemColumn} = i.{$this->itemKey}
            WHERE j.{$this->ownerColumn} = :o AND {$this->matches()}");
        $stmt->execute([':o' => $owner, ':q' => self::like($q)]);

        return (int) $stmt->fetchColumn();
    }

    /** One page of items, optionally filtered, ordered by name. */
    public function page(string|int $owner, string $q, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare("SELECT {$this->itemSelect} FROM {$this->itemTable} i
            JOIN {$this->junction} j ON j.{$this->itemColumn} = i.{$this->itemKey}
            WHERE j.{$this->ownerColumn} = :o AND {$this->matches()}
            ORDER BY i.name COLLATE NOCASE LIMIT :l OFFSET :f");
        $stmt->bindValue(':o', $owner);
        $stmt->bindValue(':q', self::like($q));
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':f', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Items matching the search that are not linked to the owner yet. */
    public function candidates(string|int $owner, string $q, int $limit): array
    {
        $stmt = $this->pdo->prepare("SELECT {$this->itemSelect} FROM {$this->itemTable} i
            WHERE {$this->matches()}
              AND NOT EXISTS (SELECT 1 FROM {$this->junction} j WHERE j.{$this->itemColumn} = i.{$this->itemKey} AND j.{$this->ownerColumn} = :o)
            ORDER BY i.name COLLATE NOCASE LIMIT :l");
        $stmt->bindValue(':o', $owner);
        $stmt->bindValue(':q', self::like($q));
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return bool whether the link is new */
    public function add(string|int $owner, string|int $item): bool
    {
        return $this->addMany($owner, [$item]) === 1;
    }

    /** @param array<int, string|int> $items @return int how many links were new */
    public function addMany(string|int $owner, array $items): int
    {
        $added = 0;
        $this->pdo->beginTransaction();
        $insert = $this->pdo->prepare("INSERT OR IGNORE INTO {$this->junction} ({$this->ownerColumn}, {$this->itemColumn}) VALUES (?, ?)");
        foreach ($items as $item) {
            $insert->execute([$owner, $item]);
            $added += $insert->rowCount();
        }
        $this->pdo->commit();

        return $added;
    }

    public function remove(string|int $owner, string|int $item): void
    {
        $this->pdo->prepare("DELETE FROM {$this->junction} WHERE {$this->ownerColumn} = ? AND {$this->itemColumn} = ?")->execute([$owner, $item]);
    }

    /** Unlinks every item matching the filter (all of them when it is empty). @return int how many */
    public function removeMatching(string|int $owner, string $q): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->junction} WHERE {$this->ownerColumn} = :o AND {$this->itemColumn} IN (
            SELECT i.{$this->itemKey} FROM {$this->itemTable} i WHERE {$this->matches()})");
        $stmt->execute([':o' => $owner, ':q' => self::like($q)]);

        return $stmt->rowCount();
    }
}
