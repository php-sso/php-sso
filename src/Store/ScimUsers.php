<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Which people a SCIM client provisioned, with that client's own identifiers for them. */
interface ScimUsers
{
    /** The person (user row plus `user_name`, `external_id`, `given_name`, `family_name`) if this client manages them. */
    public function find(int $clientId, string $sub): ?array;

    public function findByUserName(int $clientId, string $userName): ?array;

    public function findByExternalId(int $clientId, string $externalId): ?array;

    public function findByEmail(int $clientId, string $email): ?array;

    /**
     * One page of the people this client manages.
     *
     * @param array{attr: string, value: string}|null $filter `userName`, `externalId`, `email` or `id` equal to a value
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function list(int $clientId, ?array $filter, int $start, int $count): array;

    /** @throws DuplicateValue when another record of this client already has the userName, displayName or externalId */
    public function attach(int $clientId, string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void;

    /** @throws DuplicateValue when another record of this client already has the userName, displayName or externalId */
    public function update(string $sub, string $userName, ?string $externalId, ?string $givenName, ?string $familyName): void;

    /** Which client manages this person, as `id` and `name`, or null. @return array{id: int, name: string}|null */
    public function managedBy(string $sub): ?array;
}
