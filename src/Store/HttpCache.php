<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/** Answers fetched from other servers (a provider's discovery document and keys), kept for a while so sign-in does not wait on them every time. */
final class HttpCache
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** The saved answer if it is not older than `$maxAge` seconds. @return array<string, mixed>|null */
    public function get(string $key, int $maxAge, ?int $now = null): ?array
    {
        $entry = $this->entry($key);

        return $entry !== null && $entry['fetched_at'] >= ($now ?? time()) - $maxAge ? $entry['body'] : null;
    }

    /** The saved answer however old it is, with when it was fetched. @return array{body: array<string, mixed>, fetched_at: int}|null */
    public function entry(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT body, fetched_at FROM http_cache WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $body = $row ? json_decode((string) $row['body'], true) : null;

        return is_array($body) ? ['body' => $body, 'fetched_at' => (int) $row['fetched_at']] : null;
    }

    /** @param array<string, mixed> $body */
    public function put(string $key, array $body, ?int $now = null): void
    {
        $this->pdo->prepare('INSERT INTO http_cache (cache_key, body, fetched_at) VALUES (?, ?, ?)
            ON CONFLICT(cache_key) DO UPDATE SET body = excluded.body, fetched_at = excluded.fetched_at')
            ->execute([$key, json_encode($body, JSON_THROW_ON_ERROR), $now ?? time()]);
    }
}
