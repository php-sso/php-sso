<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/**
 * The sign-in requests we sent to company providers and have not had an answer to. The answer comes back as a POST from
 * another site, which a normal session cookie does not survive, so what we need to check it lives here, not in the
 * session: one row per request, used once.
 */
final class SqlSamlRequests implements \PhpSso\Store\SamlRequests
{
    private const TTL = 600;

    private PDO $pdo;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @param string $bindingHash the SHA-256 of the secret given to the browser that started this request (never the secret itself) */
    public function remember(string $requestId, int $connectionId, string $bindingHash, ?string $returnTo, ?int $now = null): void
    {
        $now ??= time();
        $this->pdo->prepare('DELETE FROM saml_requests WHERE created_at < ?')->execute([$now - self::TTL]);
        $this->pdo->prepare('INSERT INTO saml_requests (request_id, connection_id, binding_hash, return_to, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$requestId, $connectionId, $bindingHash, $returnTo, $now]);
    }

    /**
     * Takes the request out: it works once, and only while it is fresh.
     *
     * @return array{connection_id: int, binding_hash: string, return_to: ?string}|null
     */
    public function consume(string $requestId, ?int $now = null): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM saml_requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        $this->pdo->prepare('DELETE FROM saml_requests WHERE request_id = ?')->execute([$requestId]);
        if (!$row || (int) $row['created_at'] < ($now ?? time()) - self::TTL) {
            return null;
        }

        return ['connection_id' => (int) $row['connection_id'], 'binding_hash' => (string) $row['binding_hash'], 'return_to' => $row['return_to']];
    }
}
