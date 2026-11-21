<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Answers fetched from other servers (a provider's discovery document and keys), kept for a while so sign-in does not wait on them every time. */
interface HttpCache
{
    /** The saved answer if it is not older than `$maxAge` seconds. @return array<string, mixed>|null */
    public function get(string $key, int $maxAge, ?int $now = null): ?array;

    /** The saved answer however old it is, with when it was fetched. @return array{body: array<string, mixed>, fetched_at: int}|null */
    public function entry(string $key): ?array;

    /** @param array<string, mixed> $body */
    public function put(string $key, array $body, ?int $now = null): void;
}
