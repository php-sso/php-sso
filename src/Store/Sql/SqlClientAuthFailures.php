<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\ClientAuthFailures;

final class SqlClientAuthFailures implements ClientAuthFailures
{
    private PDO $pdo;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
    }

    public function times(string $ip, ?string $clientId, int $since): array
    {
        if ($clientId === null) {
            $stmt = $this->pdo->prepare('SELECT created_at FROM client_auth_failures WHERE ip = ? AND created_at > ? ORDER BY created_at');
            $stmt->execute([$ip, $since]);
        } else {
            $stmt = $this->pdo->prepare('SELECT created_at FROM client_auth_failures WHERE ip = ? AND client_id = ? AND created_at > ? ORDER BY created_at');
            $stmt->execute([$ip, $clientId, $since]);
        }

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function record(?string $clientId, string $ip, int $at, int $keepAfter): void
    {
        $this->pdo->prepare('INSERT INTO client_auth_failures (client_id, ip, created_at) VALUES (?, ?, ?)')
            ->execute([mb_substr($clientId ?? '', 0, 200), $ip, $at]);
        $this->pdo->prepare('DELETE FROM client_auth_failures WHERE created_at <= ?')->execute([$keepAfter]);
    }
}
