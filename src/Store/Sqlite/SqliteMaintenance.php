<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;
use PDOException;
use PhpSso\Store\Maintenance;

final class SqliteMaintenance implements Maintenance
{
    private PDO $pdo;

    public function __construct(Database $db, private int $idleSeconds)
    {
        $this->pdo = $db->pdo;
    }

    /** Drops rows that ended or expired more than a week ago. */
    public function purgeExpired(): void
    {
        $cutoff = time() - 7 * 86400;
        $this->pdo->prepare('DELETE FROM login_sessions WHERE COALESCE(ended_at, last_seen + ?) < CAST(? AS INTEGER)')  // CAST: bound values are text, and SQLite orders text above numbers
            ->execute([$this->idleSeconds, $cutoff]);
        // Expired codes and tokens, and revocations old enough that every access token they cover has expired.
        foreach (['oauth_codes' => 'expires_at', 'oauth_refresh_tokens' => 'expires_at', 'oauth_revoked_families' => 'revoked_at', 'oauth_revoked_tokens' => 'expires_at', 'oauth_device_requests' => 'expires_at', 'device_failures' => 'created_at'] as $table => $column) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} < ?")->execute([$cutoff]);
        }
    }

    public function isHealthy(): bool
    {
        try {
            return $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (PDOException) {
            return false;
        }
    }
}
