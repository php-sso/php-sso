<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\OAuthServer\Grant\RefreshTokenRecord;
use PhpSso\OAuthServer\Grant\RefreshTokenStore;

/**
 * Refresh tokens in SQLite, plus the list of revoked token families. A family is everything issued from one
 * authorization code: its refresh tokens and its access tokens, which carry the family id.
 */
final class SqlRefreshTokens implements RefreshTokenStore
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function save(RefreshTokenRecord $token): void
    {
        $this->pdo->prepare('INSERT INTO oauth_refresh_tokens
            (token_hash, family_id, client_id, user_id, scopes, session_id, expires_at, used_at, auth_time, amr)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $token->tokenHash, $token->familyId, $token->clientId, $token->userId, json_encode($token->scopes),
            $token->sessionId, $token->expiresAt, $token->usedAt, $token->authTime,
            $token->amr === null ? null : json_encode($token->amr),
        ]);
    }

    public function find(string $tokenHash): ?RefreshTokenRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_refresh_tokens WHERE token_hash = ?');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        return $row ? new RefreshTokenRecord(
            $row['token_hash'], $row['family_id'], $row['client_id'], $row['user_id'], json_decode($row['scopes'], true),
            $row['session_id'], (int) $row['expires_at'], $row['used_at'] === null ? null : (int) $row['used_at'],
            $row['auth_time'] === null ? null : (int) $row['auth_time'],
            self::methods($row['amr'] ?? null),
        ) : null;
    }

    public function markUsed(string $tokenHash, int $at): bool
    {
        $stmt = $this->pdo->prepare('UPDATE oauth_refresh_tokens SET used_at = ? WHERE token_hash = ? AND used_at IS NULL');
        $stmt->execute([$at, $tokenHash]);

        return $stmt->rowCount() > 0;
    }

    public function revokeFamily(string $familyId, int $at): void
    {
        $this->pdo->prepare($this->dialect->insertIgnore('oauth_revoked_families', ['family_id', 'revoked_at'], ['family_id']))->execute([$familyId, $at]);
    }

    /** Revokes every family that was started or continued in the login session, whether or not it got a refresh token. */
    public function revokeSession(string $sessionId, int $at): void
    {
        $at = $this->dialect->castInt(':at');
        $this->pdo->prepare($this->dialect->insertIgnoreSelect('oauth_revoked_families', ['family_id', 'revoked_at'], ['family_id'],
            "SELECT family_id, {$at} FROM oauth_codes WHERE session_id = :sid
            UNION
            SELECT family_id, {$at} FROM oauth_refresh_tokens WHERE session_id = :sid"))
            ->execute([':at' => $at, ':sid' => $sessionId]);
    }

    public function isFamilyRevoked(string $familyId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM oauth_revoked_families WHERE family_id = ?');
        $stmt->execute([$familyId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return list<string>|null */
    private static function methods(mixed $json): ?array
    {
        $list = is_string($json) ? json_decode($json, true) : null;

        return is_array($list) && $list !== [] ? array_values(array_map('strval', $list)) : null;
    }
}
