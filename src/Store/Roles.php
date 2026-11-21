<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Roles, and who has them. A role is only a name: the app decides what it allows. It is defined once, given to whole
 * groups or to single users, and a user has it when either applies. A role is part of the `roles` claim of every app
 * (`all_clients`) or only of the apps it was made available in.
 *
 * @phpstan-type RoleRow array{id: int, name: string, description: string, all_clients: bool, client_count: int, group_count: int, user_count: int}
 */
interface Roles
{
    /**
     * Every role, by name, each with how many clients, groups and users it was given to.
     *
     * @return list<RoleRow>
     */
    public function all(): array;

    public function count(): int;

    /**
     * The roles a client receives: those for every client and those made available in it.
     *
     * @return list<RoleRow>
     */
    public function forClient(string $clientId): array;

    /** @return RoleRow|null */
    public function find(int $id): ?array;

    /** @return RoleRow|null */
    public function findByName(string $name): ?array;

    /**
     * @param list<string> $clientIds the clients that receive it, when it is not for every client
     *
     * @throws \PDOException when there is already a role with that name (case does not matter)
     */
    public function create(string $name, string $description, bool $allClients = true, array $clientIds = []): int;

    /** @throws \PDOException when there is already another role with that name */
    public function update(int $id, string $name, string $description): void;

    /**
     * Where the role is available: every client, or exactly these. The list is kept when switching to every client,
     * so switching back remembers it.
     *
     * @param list<string> $clientIds
     */
    public function setClients(int $id, bool $allClients, array $clientIds): void;

    /** @return list<string> the client ids the role is made available in, whether or not it is for every client */
    public function clientIds(int $id): array;

    /** Whoever had the role loses it (ON DELETE CASCADE). */
    public function delete(int $id): void;

    /** Everyone in the group has the role. Does nothing when they already do. */
    public function assignGroup(int $roleId, int $groupId): void;

    /** This user has the role, whatever groups they are in. Does nothing when they already do. */
    public function assignUser(int $roleId, string $sub): void;

    /**
     * The roles a user has in a client: the roles that client receives, given to the user directly or to a group they
     * are in, each name once.
     *
     * @return list<string>
     */
    public function effectiveFor(string $sub, string $clientId): array;
}
