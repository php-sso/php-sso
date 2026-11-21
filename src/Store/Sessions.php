<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/**
 * SSO browser sessions. The PHP session holds only the id of a row here, so an administrator
 * can end a session and the next request from that browser is signed out.
 */
final class Sessions
{
    private PDO $pdo;

    /** @param int $idleSeconds a session with no request for this long counts as expired */
    public function __construct(Database $db, private int $idleSeconds)
    {
        $this->pdo = $db->pdo;
    }

    /**
     * @param string|null $impersonatedBy the administrator who is browsing as this person, for the audit trail
     * @param list<string>|null $amr how the person signed in (RFC 8176 names such as `pwd`, `otp`, `mfa`), for the ID token's `amr`
     */
    public function start(string $userSub, string $ip, string $userAgent, ?string $impersonatedBy = null, ?array $amr = null): string
    {
        $id = bin2hex(random_bytes(16));
        $now = time();
        $this->pdo->prepare('INSERT INTO login_sessions (id, user_sub, created_at, last_seen, ip, user_agent, impersonated_by, amr) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $userSub, $now, $now, $ip, mb_substr($userAgent, 0, 300), $impersonatedBy, $amr === null || $amr === [] ? null : json_encode(array_values($amr))]);

        return $id;
    }

    /** True (and refreshes the idle timer) when the session exists, belongs to the user, and is still live. */
    public function touch(string $id, string $userSub): bool
    {
        $now = time();
        $stmt = $this->pdo->prepare('UPDATE login_sessions SET last_seen = ?
            WHERE id = ? AND user_sub = ? AND ended_at IS NULL AND last_seen > ?');
        $stmt->execute([$now, $id, $userSub, $now - $this->idleSeconds]);

        return $stmt->rowCount() > 0;
    }

    /** @var (callable(array<int, array{id: string, user_sub: string}>): void)|null told which sessions just ended */
    private $onEnded = null;

    /** @param callable(array<int, array{id: string, user_sub: string}>): void $listener */
    public function onEnded(callable $listener): void
    {
        $this->onEnded = $listener;
    }

    /** Ends a session. @return int how many were ended (0 or 1) */
    public function end(string $id): int
    {
        return $this->terminate($this->select('id = ?', [$id]));
    }

    /** Ends one session, but only if it belongs to the user. */
    public function endForUser(string $id, string $userSub): int
    {
        return $this->terminate($this->select('id = ? AND user_sub = ?', [$id, $userSub]));
    }

    /** Ends every live session of the user except the given one. */
    public function endOthers(string $userSub, string $keepId): int
    {
        return $this->terminate($this->select('user_sub = ? AND id != ?', [$userSub, $keepId]));
    }

    public function endAllForUser(string $userSub): int
    {
        return $this->terminate($this->select('user_sub = ?', [$userSub]));
    }

    /**
     * Marks a session ended without telling anyone: for the end-session endpoint, which revokes and notifies
     * through the OAuth server itself, and for a session replaced by a fresh login.
     */
    public function markEnded(string $id): void
    {
        $this->pdo->prepare('UPDATE login_sessions SET ended_at = ? WHERE id = ? AND ended_at IS NULL')->execute([time(), $id]);
    }

    /** When the person signed in during this session: it is their `auth_time`. Null for an unknown session. */
    public function authTime(string $id): ?int
    {
        $stmt = $this->pdo->prepare('SELECT created_at FROM login_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** How the person signed in during this session (`pwd`, `otp`, `mfa`, ...), or null when it is not known (older sessions, impersonation). @return list<string>|null */
    public function amr(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT amr FROM login_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $list = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($list) && $list !== [] ? array_values(array_map('strval', $list)) : null;
    }

    public function addClient(string $sessionId, string $clientId): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO session_clients (session_id, client_id) VALUES (?, ?)')->execute([$sessionId, $clientId]);
    }

    /** @return string[] clients that were sent a code during this session */
    public function clientIds(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT client_id FROM session_clients WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int, array{id: string, user_sub: string}> sessions not ended yet that match the condition */
    private function select(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare("SELECT id, user_sub FROM login_sessions WHERE ended_at IS NULL AND {$where}");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Marks the sessions ended, then tells the listener (the OAuth server's LogoutService), which revokes
     * the codes and tokens issued during them and notifies the apps that support back-channel logout.
     */
    private function terminate(array $sessions): int
    {
        if ($sessions === []) {
            return 0;
        }
        $ids = array_column($sessions, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("UPDATE login_sessions SET ended_at = ? WHERE id IN ({$in})")->execute([time(), ...$ids]);

        if ($this->onEnded !== null) {
            ($this->onEnded)($sessions);
        }

        return count($sessions);
    }

    /** Live sessions, most recently active first, with the user's name. */
    public function active(): array
    {
        $stmt = $this->pdo->prepare('SELECT s.id, s.created_at, s.last_seen, s.ip, s.user_agent,
                u.sub, u.username, u.name, a.name AS impersonated_by_name
            FROM login_sessions s JOIN users u ON u.sub = s.user_sub
            LEFT JOIN users a ON a.sub = s.impersonated_by
            WHERE s.ended_at IS NULL AND s.last_seen > ?
            ORDER BY s.last_seen DESC');
        $stmt->execute([time() - $this->idleSeconds]);

        return $stmt->fetchAll();
    }

    /** Drops rows that ended or expired more than a week ago. */
    public function prune(): void
    {
        $cutoff = time() - 7 * 86400;
        $this->pdo->prepare('DELETE FROM login_sessions WHERE COALESCE(ended_at, last_seen + ?) < CAST(? AS INTEGER)')  // CAST: bound values are text, and SQLite orders text above numbers
            ->execute([$this->idleSeconds, $cutoff]);
        // Expired codes and tokens, and revocations old enough that every access token they cover has expired.
        foreach (['oauth_codes' => 'expires_at', 'oauth_refresh_tokens' => 'expires_at', 'oauth_revoked_families' => 'revoked_at', 'oauth_revoked_tokens' => 'expires_at', 'oauth_device_requests' => 'expires_at', 'device_failures' => 'created_at'] as $table => $column) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE {$column} < ?")->execute([$cutoff]);
        }
    }
}
