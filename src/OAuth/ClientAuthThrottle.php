<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Store\Sqlite\Database;
use PDO;

/**
 * Slows down guessing of client secrets. A failure counts against the client and the address together, and against
 * the address alone, so an attacker is stopped without being able to lock a real app out by naming its client id.
 */
final class ClientAuthThrottle
{
    /** @param array{enabled?: bool, per_client_ip?: int, per_ip?: int, window_seconds?: int} $limits */
    public function __construct(private Database $db, private array $limits = [])
    {
    }

    /** Seconds until this address may try again, or null when nothing stops it. */
    public function lockedFor(?string $clientId, string $ip, ?int $now = null): ?int
    {
        $limits = $this->resolved();
        if (!$limits['enabled']) {
            return null;
        }
        $now ??= time();
        $since = $now - $limits['window_seconds'];

        $waits = [];
        $perIp = $this->times('SELECT created_at FROM client_auth_failures WHERE ip = ? AND created_at > ? ORDER BY created_at', [$ip, $since]);
        if (count($perIp) >= $limits['per_ip']) {
            $waits[] = $perIp[count($perIp) - $limits['per_ip']] + $limits['window_seconds'] - $now;
        }
        if ($clientId !== null) {
            $perClient = $this->times('SELECT created_at FROM client_auth_failures WHERE ip = ? AND client_id = ? AND created_at > ? ORDER BY created_at', [$ip, $clientId, $since]);
            if (count($perClient) >= $limits['per_client_ip']) {
                $waits[] = $perClient[count($perClient) - $limits['per_client_ip']] + $limits['window_seconds'] - $now;
            }
        }

        return $waits === [] ? null : max(1, ...$waits);
    }

    public function recordFailure(?string $clientId, string $ip, ?int $now = null): void
    {
        $limits = $this->resolved();
        if (!$limits['enabled']) {
            return;
        }
        $now ??= time();
        $pdo = $this->db->pdo;
        $pdo->prepare('INSERT INTO client_auth_failures (client_id, ip, created_at) VALUES (?, ?, ?)')
            ->execute([mb_substr($clientId ?? '', 0, 200), $ip, $now]);
        $pdo->prepare('DELETE FROM client_auth_failures WHERE created_at <= ?')->execute([$now - $limits['window_seconds']]);
    }

    /** @return array{enabled: bool, per_client_ip: int, per_ip: int, window_seconds: int} */
    private function resolved(): array
    {
        return [
            'enabled' => (bool) ($this->limits['enabled'] ?? true),
            'per_client_ip' => max(1, (int) ($this->limits['per_client_ip'] ?? 10)),
            'per_ip' => max(1, (int) ($this->limits['per_ip'] ?? 30)),
            'window_seconds' => max(60, (int) ($this->limits['window_seconds'] ?? 900)),
        ];
    }

    /**
     * @param list<string|int> $params
     * @return list<int>
     */
    private function times(string $sql, array $params): array
    {
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
