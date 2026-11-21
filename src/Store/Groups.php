<?php

declare(strict_types=1);

namespace PhpSso\Store;

interface Groups
{
    /** All groups, each with a `members` count. */
    public function all(): array;

    public function find(int $id): ?array;

    public function findByName(string $name): ?array;

    public function count(): int;

    public function create(string $name, string $description): int;

    public function update(int $id, string $name, string $description): void;

    /** Memberships are removed with it (ON DELETE CASCADE). */
    public function delete(int $id): void;

    /** Groups whose name or description contains the text, with member counts; for the admin search. */
    public function search(string $q, int $limit): array;
}
