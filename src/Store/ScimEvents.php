<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/** What a provisioning client asked for and how it went, newest first, so an administrator can see what the provider did. */
final class ScimEvents
{
    private const KEEP = 2000;

    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function record(int $clientId, string $method, string $path, int $status, string $detail = '', ?int $now = null): void
    {
        $this->pdo->prepare('INSERT INTO scim_events (client_id, at, method, path, status, detail) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$clientId, $now ?? time(), $method, mb_substr($path, 0, 200), $status, mb_substr($detail, 0, 300)]);
        // Keep the table small: drop what is far older than the newest rows.
        $this->pdo->exec('DELETE FROM scim_events WHERE id <= (SELECT MAX(id) FROM scim_events) - ' . self::KEEP);
    }

    /** @return list<array{at: int, method: string, path: string, status: int, detail: string}> */
    public function recent(int $clientId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT at, method, path, status, detail FROM scim_events WHERE client_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $clientId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
