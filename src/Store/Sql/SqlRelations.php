<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PhpSso\Store\Relation;

/** The owner/item links managed through the admin relation picker, by name. */
final class SqlRelations implements \PhpSso\Store\Relations
{
    /** @var array<string, Relation> */
    private array $all;

    public function __construct(Connection $db)
    {
        $this->all = [
            'group-users' => SqlRelation::groupUsers($db),
            'user-groups' => SqlRelation::userGroups($db),
            'client-groups' => SqlRelation::clientGroups($db),
            'client-users' => SqlRelation::clientUsers($db),
            'role-groups' => SqlRelation::roleGroups($db),
            'role-users' => SqlRelation::roleUsers($db),
        ];
    }

    public function get(string $name): ?Relation
    {
        return $this->all[$name] ?? null;
    }
}
