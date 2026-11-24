<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Allow and deny rules for the IP addresses people may sign in from. Each rule is an address or CIDR range.
 * A rule belongs to one client, or (client id null) applies to everything.
 */
interface IpRules
{
    /** @return array<int, array{id: int, client_id: ?string, type: string, cidr: string, note: string, created_at: int}> */
    public function all(?string $clientId = null): array;

    /** @return string[] ranges of the given type ('allow' or 'deny') */
    public function ranges(string $type, ?string $clientId = null): array;

    /** Whether the client is judged by its own rules only, without the general ones. */
    public function ignoresGeneral(string $clientId): bool;

    public function setIgnoresGeneral(string $clientId, bool $ignores): void;

    public function find(int $id): ?array;

    public function exists(string $type, string $cidr, ?string $clientId = null): bool;

    public function create(string $type, string $cidr, string $note, ?string $clientId = null): int;

    public function delete(int $id): void;

    /** @return array<int, array{id: int, user_sub: string, type: string, cidr: string, note: string, created_at: int}> the rules of one person */
    public function allForUser(string $sub): array;

    /** @return string[] ranges of the given type ('allow' or 'deny') that belong to the person */
    public function userRanges(string $type, string $sub): array;

    public function findForUser(int $id): ?array;

    public function existsForUser(string $type, string $cidr, string $sub): bool;

    public function createForUser(string $type, string $cidr, string $note, string $sub): int;

    public function deleteForUser(int $id): void;
}
