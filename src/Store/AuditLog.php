<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Who did what, and when: the administrative and security-relevant actions. Rows are only ever added (and removed when they are
 * older than the retention period), never changed. The actor and the target are copied in by name, so a row still says what it meant
 * after the person or the app it names was deleted.
 *
 * @phpstan-type Entry array{actor_sub?: string|null, actor: string, ip?: string, action: string, target_type?: string|null, target?: string|null, target_label?: string|null, detail?: array<string, mixed>}
 * @phpstan-type Row array{id: int, at: int, actor_sub: string|null, actor: string, ip: string, action: string, target_type: string|null, target: string|null, target_label: string|null, detail: array<string, mixed>}
 * @phpstan-type Filter array{action?: string, actor?: string, target?: string, since?: int, until?: int}
 */
interface AuditLog
{
    /** @param Entry $entry */
    public function record(array $entry, ?int $now = null): void;

    /**
     * Newest first. Filters: `action` (starts with, e.g. `user.`), `actor` (a name contains it, or the id is it), `target` (its id or name contains it),
     * `since` and `until` (unix times, both inclusive).
     *
     * @param Filter $filter
     * @return list<Row>
     */
    public function search(array $filter, int $limit, int $offset = 0): array;

    /** @param Filter $filter how many rows match */
    public function count(array $filter): int;

    /** @return int how many rows were removed */
    public function deleteOlderThan(int $time): int;
}
