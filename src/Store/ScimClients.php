<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * The systems allowed to provision people through SCIM, each with a token and the email domains it may manage. The
 * token is long and random, shown once, and only its SHA-256 is kept, so a copy of the database does not give access.
 */
interface ScimClients
{
    public const ON_DELETE = ['deactivate', 'delete'];

    /** @return list<array<string, mixed>> every client with its `domains` (a list) and `people` (how many it provisioned) */
    public function all(): array;

    public function find(int $id): ?array;

    /**
     * @param list<string> $domains
     * @return array{id: int, token: string} the token is not kept and cannot be shown again
     */
    public function create(string $name, array $domains, bool $enabled, string $onDelete, bool $adoptExisting, string $groupPrefix = '', bool $adoptGroups = false): array;

    /** @param list<string> $domains */
    public function update(int $id, string $name, array $domains, bool $enabled, string $onDelete, bool $adoptExisting, string $groupPrefix = '', bool $adoptGroups = false): void;

    /** Makes a new token: the old one stops working at once. @return string the new token, shown once */
    public function rotate(int $id): string;

    /** Removes the client. The people it provisioned stay, no longer managed by anyone. */
    public function delete(int $id): void;

    /** The switched-on client this token belongs to, or null. A use is noted at most once a minute. */
    public function authenticate(string $token): ?array;
}
