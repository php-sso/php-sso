<?php

declare(strict_types=1);

namespace PhpSso\Store;

interface Users
{
    /** All users, each with a `groups` list of group names. */
    public function all(): array;

    /**
     * One page of people, by username, each with a `groups` list of group names. With text, only those whose name, username or email contains it.
     * The group names of the page come in one query, so a page costs the same however many people there are.
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $q, int $limit, int $offset): array;

    /** How many people {@see page()} has in all for this text (everyone when it is empty). */
    public function countMatching(string $q): int;

    /** Administrators only, without the per-user group lookup that all() does. */
    public function admins(): array;

    /** People whose name, username or email contains the text; for the admin search. */
    public function search(string $q, int $limit): array;

    public function find(string $sub): ?array;

    public function findByUsername(string $username): ?array;

    /** The person with this email address (ignoring case), or null when no one or more than one has it. */
    public function findByEmail(string $email): ?array;

    public function count(): int;

    public function adminCount(): int;

    /** Whether another person (not `$exceptSub`) has this email address, ignoring case. */
    public function emailTaken(string $email, ?string $exceptSub = null): bool;

    /**
     * @param array{username: string, password: string|null, name: string, email: string, email_verified: bool, is_admin: bool, must_change_password?: bool} $data
     * @return string the new user's `sub`; a null password creates a person with no local password
     */
    public function create(array $data): string;

    /** Same fields as create(); an empty password keeps the current one, and a missing `must_change_password` leaves the flag as it is. */
    public function update(string $sub, array $data): void;

    public function updateProfile(string $sub, string $name, string $email, bool $emailVerified): void;

    /** Locks or unlocks the account by hand. Ending its sessions is up to the caller. */
    public function setLocked(string $sub, bool $locked): void;

    /** Requires (or stops requiring) the person to choose a new password at their next sign-in. */
    public function setMustChangePassword(string $sub, bool $must): void;

    /** Sets a password the person chose, which also ends any requirement to choose one. */
    public function setPassword(string $sub, string $password): void;

    public function delete(string $sub): void;

    /** @return string[] */
    public function groupNames(string $sub): array;

    /** @return int[] */
    public function groupIds(string $sub): array;

    /** @param int[] $groupIds */
    public function setGroups(string $sub, array $groupIds): void;
}
