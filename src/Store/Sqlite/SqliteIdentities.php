<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/** Which accounts at outside providers belong to which person. */
final class SqliteIdentities implements \PhpSso\Store\Identities
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @return array{id: int, user_sub: string, provider: string, subject: string, email: ?string, created_at: int, last_login_at: ?int}|null */
    public function find(string $provider, string $subject): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_identities WHERE provider = ? AND subject = ?');
        $stmt->execute([$provider, $subject]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(string $userSub): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_identities WHERE user_sub = ? ORDER BY provider');
        $stmt->execute([$userSub]);

        return $stmt->fetchAll();
    }

    /** Connects the provider's account to the person. A provider account belongs to one person only (the database insists). */
    public function link(string $userSub, string $provider, string $subject, ?string $email): void
    {
        $this->pdo->prepare('INSERT INTO user_identities (user_sub, provider, subject, email, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userSub, $provider, $subject, $email, time()]);
    }

    /** Notes a sign-in, and the email the provider gave this time. */
    public function touch(int $id, ?string $email): void
    {
        $this->pdo->prepare('UPDATE user_identities SET last_login_at = ?, email = COALESCE(?, email) WHERE id = ?')
            ->execute([time(), $email, $id]);
    }

    /** Disconnects the person's account at the provider. @return bool whether there was one */
    public function unlink(string $userSub, string $provider): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_identities WHERE user_sub = ? AND provider = ?');
        $stmt->execute([$userSub, $provider]);

        return $stmt->rowCount() > 0;
    }
}
