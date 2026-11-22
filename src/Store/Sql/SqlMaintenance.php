<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;
use PhpSso\Store\Maintenance;
use PhpSso\Store\Sql\Migrations\Migration;

final class SqlMaintenance implements Maintenance
{
    private PDO $pdo;
    private Dialect $dialect;
    private Connection $db;

    public function __construct(Connection $db, private int $idleSeconds, private int $auditRetentionSeconds = 0)
    {
        $this->db = $db;
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    /** Drops rows that ended or expired more than a week ago. */
    public function purgeExpired(): void
    {
        $cutoff = time() - 7 * 86400;
        $this->pdo->prepare('DELETE FROM login_sessions WHERE COALESCE(ended_at, last_seen + ?) < ' . $this->dialect->castInt('?'))  // CAST: bound values are text, and SQLite orders text above numbers
            ->execute([$this->idleSeconds, $cutoff]);
        // Expired codes and tokens, and revocations old enough that every access token they cover has expired.
        foreach (['oauth_codes' => 'expires_at', 'oauth_refresh_tokens' => 'expires_at', 'oauth_revoked_families' => 'revoked_at', 'oauth_revoked_tokens' => 'expires_at', 'oauth_device_requests' => 'expires_at', 'device_failures' => 'created_at'] as $table => $column) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} < ?")->execute([$cutoff]);
        }
        if ($this->auditRetentionSeconds > 0) { // the audit log is kept for the retention period the operator chose, not for the week the rest is
            $this->pdo->prepare('DELETE FROM audit_log WHERE at < ?')->execute([time() - $this->auditRetentionSeconds]);
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

    public function unknownMigrations(): array
    {
        return $this->db->migrator()->unknown();
    }

    public function pendingMigrations(): array
    {
        return array_map(static fn (Migration $m): string => $m->id(), $this->db->migrator()->pending());
    }
}
