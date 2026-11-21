<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Which groups a SCIM client provisioned, with that client's own name and id for them, and who is in them. */
interface ScimGroups
{
    /** The group (group row plus `client_id`, `display_name`, `external_id`) if this client manages it. */
    public function find(int $clientId, int $groupId): ?array;

    public function findByDisplayName(int $clientId, string $displayName): ?array;

    public function findByExternalId(int $clientId, string $externalId): ?array;

    /**
     * One page of the groups this client manages.
     *
     * @param array{attr: string, value: string}|null $filter `displayName`, `externalId` or `id` equal to a value
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function list(int $clientId, ?array $filter, int $start, int $count): array;

    public function attach(int $clientId, int $groupId, string $displayName, ?string $externalId): void;

    public function update(int $groupId, string $displayName, ?string $externalId): void;

    /** Which client manages this group, as `id` and `name`, or null. @return array{id: int, name: string}|null */
    public function managedBy(int $groupId): ?array;

    /** @return list<string> the `sub` of everyone in the group */
    public function members(int $groupId): array;

    /** @param list<string> $subs */
    public function addMembers(int $groupId, array $subs): void;

    /** @param list<string> $subs */
    public function removeMembers(int $groupId, array $subs): void;

    /** Makes exactly these people the members. @param list<string> $subs */
    public function replaceMembers(int $groupId, array $subs): void;
}
