<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PhpSso\Store\Relation;

/** The owner/item links managed through the admin relation picker, by name. */
final class SqliteRelations implements \PhpSso\Store\Relations
{
    /** @var array<string, Relation> */
    private array $all;

    public function __construct(Database $db)
    {
        $this->all = [
            'group-users' => SqliteRelation::groupUsers($db),
            'user-groups' => SqliteRelation::userGroups($db),
            'client-groups' => SqliteRelation::clientGroups($db),
            'client-users' => SqliteRelation::clientUsers($db),
            'role-groups' => SqliteRelation::roleGroups($db),
            'role-users' => SqliteRelation::roleUsers($db),
        ];
    }

    public function get(string $name): ?Relation
    {
        return $this->all[$name] ?? null;
    }
}
