<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;
use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;
use PhpSso\OAuthServer\Grant\AuthorizationCodeStore;

/** Authorization codes in SQLite. Only the hash of a code is stored. */
final class SqliteAuthorizationCodes implements AuthorizationCodeStore
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function save(AuthorizationCodeRecord $code): void
    {
        $this->pdo->prepare('INSERT INTO oauth_codes
            (code_hash, client_id, user_id, redirect_uri, scopes, code_challenge, nonce, session_id, family_id, expires_at, used_at, auth_time, amr)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $code->codeHash, $code->clientId, $code->userId, $code->redirectUri, json_encode($code->scopes),
            $code->codeChallenge, $code->nonce, $code->sessionId, $code->familyId, $code->expiresAt, $code->usedAt, $code->authTime,
            $code->amr === null ? null : json_encode($code->amr),
        ]);
    }

    public function find(string $codeHash): ?AuthorizationCodeRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_codes WHERE code_hash = ?');
        $stmt->execute([$codeHash]);
        $row = $stmt->fetch();

        return $row ? new AuthorizationCodeRecord(
            $row['code_hash'], $row['client_id'], $row['user_id'], $row['redirect_uri'], json_decode($row['scopes'], true),
            $row['code_challenge'], $row['nonce'], $row['session_id'], $row['family_id'], (int) $row['expires_at'],
            $row['used_at'] === null ? null : (int) $row['used_at'],
            $row['auth_time'] === null ? null : (int) $row['auth_time'],
            self::methods($row['amr'] ?? null),
        ) : null;
    }

    /** One caller wins. A code whose login session ended in the meantime (its family was revoked) cannot be redeemed. */
    public function markUsed(string $codeHash, int $at): bool
    {
        $stmt = $this->pdo->prepare('UPDATE oauth_codes SET used_at = ?
            WHERE code_hash = ? AND used_at IS NULL AND family_id NOT IN (SELECT family_id FROM oauth_revoked_families)');
        $stmt->execute([$at, $codeHash]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<string>|null */
    private static function methods(mixed $json): ?array
    {
        $list = is_string($json) ? json_decode($json, true) : null;

        return is_array($list) && $list !== [] ? array_values(array_map('strval', $list)) : null;
    }
}
