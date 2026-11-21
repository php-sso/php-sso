<?php

declare(strict_types=1);

namespace PhpSso\Store;

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
interface Relation
{
    public function count(string|int $owner, string $q): int;

    /** One page of items, optionally filtered, ordered by name. */
    public function page(string|int $owner, string $q, int $limit, int $offset): array;

    /** Items matching the search that are not linked to the owner yet. */
    public function candidates(string|int $owner, string $q, int $limit): array;

    /** @return bool whether the link is new */
    public function add(string|int $owner, string|int $item): bool;

    /** @param array<int, string|int> $items @return int how many links were new */
    public function addMany(string|int $owner, array $items): int;

    public function remove(string|int $owner, string|int $item): void;

    /** Unlinks every item matching the filter (all of them when it is empty). @return int how many */
    public function removeMatching(string|int $owner, string $q): int;
}
