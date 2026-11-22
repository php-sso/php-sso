<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Store\AuditLog;

/** An audit log that keeps its entries in an array (or fails, to see what the caller does about it). */
final class InMemoryAuditLog implements AuditLog
{
    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public function __construct(private bool $failing = false)
    {
    }

    public function record(array $entry, ?int $now = null): void
    {
        if ($this->failing) {
            throw new \RuntimeException('the database is down');
        }
        $this->entries[] = $entry;
    }

    public function search(array $filter, int $limit, int $offset = 0): array
    {
        return [];
    }

    public function count(array $filter): int
    {
        return count($this->entries);
    }

    public function deleteOlderThan(int $time): int
    {
        return 0;
    }
}
