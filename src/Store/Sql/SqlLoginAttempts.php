<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/** History of sign-in attempts (successful and failed). Passwords are never stored. */
final class SqlLoginAttempts implements \PhpSso\Store\LoginAttempts
{
    /** Newest rows kept. The limits read these rows, so the history must be longer than anyone can fill within a window. */
    private const KEEP = 5000;

    /** Attempts that count towards a limit: failed passwords, not refusals and not ones an administrator reset. */
    private const COUNTED = 'success = 0 AND blocked = 0 AND throttled = 0 AND cleared = 0';

    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    /** @param string $factor what was checked: `password`, or `code` for the second step */
    public function record(string $username, ?string $userSub, bool $success, string $ip, string $userAgent, bool $blocked = false, bool $throttled = false, string $factor = 'password'): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (username, user_sub, success, ip, user_agent, created_at, blocked, throttled, factor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([mb_substr($username, 0, 100), $userSub, (int) $success, $ip, mb_substr($userAgent, 0, 300), time(), (int) $blocked, (int) $throttled, $factor === 'code' ? 'code' : 'password']);

        $this->pdo->prepare('DELETE FROM login_attempts WHERE id <= (SELECT m FROM (SELECT MAX(id) AS m FROM login_attempts) t) - ?')
            ->execute([self::KEEP]);
    }

    public function recent(int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM login_attempts ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function failedSince(int $timestamp): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?');
        $stmt->execute([$timestamp]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * When the counted failures since `$since` happened (oldest first), for an identifier (ignoring case) or for the
     * account it belongs to.
     *
     * @return int[]
     */
    public function failureTimesForIdentifier(string $identifier, ?string $userSub, int $since): array
    {
        $stmt = $this->pdo->prepare('SELECT created_at FROM login_attempts
            WHERE ' . self::COUNTED . ' AND created_at >= :since
              AND (' . $this->dialect->ci('username') . ' = ' . $this->dialect->ciValue(':identifier') . ' OR user_sub = :sub)
            ORDER BY created_at, id');
        $stmt->execute(['since' => $since, 'identifier' => mb_substr($identifier, 0, 100), 'sub' => $userSub]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * When the counted failures since `$since` from one address happened (oldest first).
     *
     * @return int[]
     */
    public function failureTimesFromIp(string $ip, int $since): array
    {
        $stmt = $this->pdo->prepare('SELECT created_at FROM login_attempts WHERE ' . self::COUNTED . ' AND ip = ? AND created_at >= ? ORDER BY created_at, id');
        $stmt->execute([$ip, $since]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{username: string, user_sub: ?string, ip: string, created_at: int}> every counted failure since the time, oldest first */
    public function countedFailures(int $since): array
    {
        $stmt = $this->pdo->prepare('SELECT username, user_sub, ip, created_at FROM login_attempts WHERE ' . self::COUNTED . ' AND created_at >= ? ORDER BY created_at, id');
        $stmt->execute([$since]);

        return array_map(static fn (array $r): array => ['created_at' => (int) $r['created_at']] + $r, $stmt->fetchAll());
    }

    /** Whether a refusal for this address or identifier was already recorded since the time (one is enough to show). */
    public function hasThrottledSince(string $ip, string $identifier, int $since): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM login_attempts WHERE throttled = 1 AND created_at >= ? AND (ip = ? OR ' . $this->dialect->ci('username') . ' = ' . $this->dialect->ciValue('?') . ') LIMIT 1');
        $stmt->execute([$since, $ip, mb_substr($identifier, 0, 100)]);

        return $stmt->fetchColumn() !== false;
    }

    public function recentThrottled(int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM login_attempts WHERE throttled = 1 ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Stops the failures of an account (and of the identifiers given, ignoring case) counting towards a limit.
     * The attempts stay in the history.
     *
     * @param string[] $identifiers
     * @return int how many failures stopped counting
     */
    public function clearForAccount(string $userSub, array $identifiers = []): int
    {
        $names = array_map(static fn (string $i): string => mb_substr($i, 0, 100), $identifiers);
        $marks = implode(',', array_fill(0, count($names), $this->dialect->ciValue('?')));
        $stmt = $this->pdo->prepare('UPDATE login_attempts SET cleared = 1 WHERE ' . self::COUNTED . ' AND (user_sub = ?' . ($names === [] ? '' : " OR {$this->dialect->ci('username')} IN ({$marks})") . ')');
        $stmt->execute([$userSub, ...$names]);

        return $stmt->rowCount();
    }

    /** Same, for an identifier that belongs to no account. */
    public function clearForIdentifier(string $identifier): int
    {
        $stmt = $this->pdo->prepare('UPDATE login_attempts SET cleared = 1 WHERE ' . self::COUNTED . ' AND ' . $this->dialect->ci('username') . ' = ' . $this->dialect->ciValue('?'));
        $stmt->execute([mb_substr($identifier, 0, 100)]);

        return $stmt->rowCount();
    }

    /** Same, for the failures from one address. */
    public function clearForIp(string $ip): int
    {
        $stmt = $this->pdo->prepare('UPDATE login_attempts SET cleared = 1 WHERE ' . self::COUNTED . ' AND ip = ?');
        $stmt->execute([$ip]);

        return $stmt->rowCount();
    }
}
