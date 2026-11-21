<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;

/** The owner/item links managed through the admin relation picker, by name. */
final class Relations
{
    /** @var array<string, Relation> */
    private array $all;

    public function __construct(Database $db)
    {
        $this->all = [
            'group-users' => Relation::groupUsers($db),
            'user-groups' => Relation::userGroups($db),
            'client-groups' => Relation::clientGroups($db),
            'client-users' => Relation::clientUsers($db),
            'role-groups' => Relation::roleGroups($db),
            'role-users' => Relation::roleUsers($db),
        ];
    }

    public function get(string $name): ?Relation
    {
        return $this->all[$name] ?? null;
    }
}
