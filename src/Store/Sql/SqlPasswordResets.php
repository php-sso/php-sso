<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\PasswordResets;

final class SqlPasswordResets implements PasswordResets
{
    private PDO $pdo;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
    }

    public function record(string $ip, ?int $now = null): void
    {
        $this->pdo->prepare('INSERT INTO password_resets (user_sub, token_hash, ip, created_at, expires_at) VALUES (NULL, NULL, ?, ?, 0)')->execute([mb_substr($ip, 0, 64), $now ?? time()]);
    }

    public function issue(string $userSub, string $tokenHash, int $expiresAt, string $ip, ?int $now = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->revokeFor($userSub);
            $this->pdo->prepare('INSERT INTO password_resets (user_sub, token_hash, ip, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$userSub, $tokenHash, mb_substr($ip, 0, 64), $now ?? time(), $expiresAt]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function requestsFrom(string $ip, int $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at >= ?');
        $stmt->execute([mb_substr($ip, 0, 64), $since]);

        return (int) $stmt->fetchColumn();
    }

    public function requestsFor(string $userSub, int $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_sub = ? AND created_at >= ?');
        $stmt->execute([$userSub, $since]);

        return (int) $stmt->fetchColumn();
    }

    public function find(string $tokenHash, ?int $now = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_sub FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
        $stmt->execute([$tokenHash, $now ?? time()]);
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id'], 'user_sub' => (string) $row['user_sub']];
    }

    public function consume(int $id, ?int $now = null): bool
    {
        $now ??= time();
        $stmt = $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL AND token_hash IS NOT NULL AND expires_at > ?');
        $stmt->execute([$now, $id, $now]);

        return $stmt->rowCount() === 1;
    }

    public function revokeFor(string $userSub): void
    {
        $this->pdo->prepare('UPDATE password_resets SET token_hash = NULL WHERE user_sub = ? AND used_at IS NULL AND token_hash IS NOT NULL')->execute([$userSub]);
    }

    public function deleteOlderThan(int $time): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_resets WHERE created_at < ?');
        $stmt->execute([$time]);

        return $stmt->rowCount();
    }
}
