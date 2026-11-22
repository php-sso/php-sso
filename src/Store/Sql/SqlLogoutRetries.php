<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\LogoutRetries;

final class SqlLogoutRetries implements LogoutRetries
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function enqueue(string $sessionId, string $userSub, string $clientId, string $error, int $nextAt, ?int $now = null): void
    {
        $this->pdo->prepare($this->dialect->insertIgnore('logout_retries', ['session_id', 'user_sub', 'client_id', 'attempts', 'next_at', 'created_at', 'last_error'], ['session_id', 'client_id']))
            ->execute([mb_substr($sessionId, 0, 255), mb_substr($userSub, 0, 255), mb_substr($clientId, 0, 255), 1, $nextAt, $now ?? time(), mb_substr($error, 0, 500)]);
    }

    public function due(int $now, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT id, session_id, user_sub, client_id, attempts FROM logout_retries WHERE next_at <= :now ORDER BY next_at, id LIMIT :l');
        $stmt->bindValue(':now', $now, PDO::PARAM_INT);
        $stmt->bindValue(':l', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'session_id' => (string) $r['session_id'], 'user_sub' => (string) $r['user_sub'],
            'client_id' => (string) $r['client_id'], 'attempts' => (int) $r['attempts']], $stmt->fetchAll());
    }

    public function claim(int $id, int $now, int $leaseUntil): bool
    {
        $stmt = $this->pdo->prepare('UPDATE logout_retries SET next_at = ? WHERE id = ? AND next_at <= ?');
        $stmt->execute([$leaseUntil, $id, $now]);

        return $stmt->rowCount() === 1;
    }

    public function reschedule(int $id, int $attempts, int $nextAt, string $error): void
    {
        $this->pdo->prepare('UPDATE logout_retries SET attempts = ?, next_at = ?, last_error = ? WHERE id = ?')->execute([$attempts, $nextAt, mb_substr($error, 0, 500), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM logout_retries WHERE id = ?')->execute([$id]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM logout_retries')->fetchColumn();
    }
}
