<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\OAuthServer\Grant\RevokedAccessTokens as RevokedAccessTokensStore;

/** Access tokens a client revoked one by one (RFC 7009), kept until they would have expired anyway. */
final class SqlRevokedAccessTokens implements RevokedAccessTokensStore
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function revoke(string $tokenId, int $expiresAt): void
    {
        $this->pdo->prepare($this->dialect->insertIgnore('oauth_revoked_tokens', ['token_id', 'expires_at'], ['token_id']))->execute([$tokenId, $expiresAt]);
    }

    public function isRevoked(string $tokenId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM oauth_revoked_tokens WHERE token_id = ?');
        $stmt->execute([$tokenId]);

        return $stmt->fetchColumn() !== false;
    }
}
