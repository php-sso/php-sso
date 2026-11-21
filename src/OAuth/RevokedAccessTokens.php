<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Database;
use PDO;
use PhpSso\OAuthServer\Grant\RevokedAccessTokens as RevokedAccessTokensStore;

/** Access tokens a client revoked one by one (RFC 7009), kept until they would have expired anyway. */
final class RevokedAccessTokens implements RevokedAccessTokensStore
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function revoke(string $tokenId, int $expiresAt): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO oauth_revoked_tokens (token_id, expires_at) VALUES (?, ?)')->execute([$tokenId, $expiresAt]);
    }

    public function isRevoked(string $tokenId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM oauth_revoked_tokens WHERE token_id = ?');
        $stmt->execute([$tokenId]);

        return $stmt->fetchColumn() !== false;
    }
}
