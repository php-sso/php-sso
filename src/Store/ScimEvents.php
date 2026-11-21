<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What a provisioning client asked for and how it went, newest first, so an administrator can see what the provider did. */
interface ScimEvents
{
    public function record(int $clientId, string $method, string $path, int $status, string $detail = '', ?int $now = null): void;

    /** @return list<array{at: int, method: string, path: string, status: int, detail: string}> */
    public function recent(int $clientId, int $limit = 20): array;
}
