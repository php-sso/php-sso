<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/**
 * The systems allowed to provision people through SCIM, each with a token and the email domains it may manage. The
 * token is long and random, shown once, and only its SHA-256 is kept, so a copy of the database does not give access.
 */
final class SqliteScimClients implements \PhpSso\Store\ScimClients
{
    public const ON_DELETE = ['deactivate', 'delete'];

    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @return list<array<string, mixed>> every client with its `domains` (a list) and `people` (how many it provisioned) */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT c.*, (SELECT COUNT(*) FROM scim_users u WHERE u.client_id = c.id) AS people, (SELECT COUNT(*) FROM scim_groups g WHERE g.client_id = c.id) AS groups FROM scim_clients c ORDER BY c.name COLLATE NOCASE, c.id')->fetchAll();

        return array_map([$this, 'shape'], $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM scim_users u WHERE u.client_id = c.id) AS people, (SELECT COUNT(*) FROM scim_groups g WHERE g.client_id = c.id) AS groups FROM scim_clients c WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->shape($row) : null;
    }

    /**
     * @param list<string> $domains
     * @return array{id: int, token: string} the token is not kept and cannot be shown again
     */
    public function create(string $name, array $domains, bool $enabled, string $onDelete, bool $adoptExisting, string $groupPrefix = '', bool $adoptGroups = false): array
    {
        $token = self::newToken();
        $this->pdo->prepare('INSERT INTO scim_clients (name, token_hash, domains, enabled, on_delete, adopt_existing, group_prefix, adopt_groups, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, self::hash($token), self::domainsJson($domains), (int) $enabled, $onDelete, (int) $adoptExisting, $groupPrefix, (int) $adoptGroups, time()]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'token' => $token];
    }

    /** @param list<string> $domains */
    public function update(int $id, string $name, array $domains, bool $enabled, string $onDelete, bool $adoptExisting, string $groupPrefix = '', bool $adoptGroups = false): void
    {
        $this->pdo->prepare('UPDATE scim_clients SET name = ?, domains = ?, enabled = ?, on_delete = ?, adopt_existing = ?, group_prefix = ?, adopt_groups = ? WHERE id = ?')
            ->execute([$name, self::domainsJson($domains), (int) $enabled, $onDelete, (int) $adoptExisting, $groupPrefix, (int) $adoptGroups, $id]);
    }

    /** Makes a new token: the old one stops working at once. @return string the new token, shown once */
    public function rotate(int $id): string
    {
        $token = self::newToken();
        $this->pdo->prepare('UPDATE scim_clients SET token_hash = ? WHERE id = ?')->execute([self::hash($token), $id]);

        return $token;
    }

    /** Removes the client. The people it provisioned stay, no longer managed by anyone. */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM scim_clients WHERE id = ?')->execute([$id]);
    }

    /** The switched-on client this token belongs to, or null. A use is noted at most once a minute. */
    public function authenticate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM scim_clients WHERE token_hash = ? AND enabled = 1');
        $stmt->execute([self::hash($token)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($row['last_used_at'] === null || (int) $row['last_used_at'] < time() - 60) {
            $this->pdo->prepare('UPDATE scim_clients SET last_used_at = ? WHERE id = ?')->execute([time(), $row['id']]);
        }

        return $this->shape($row);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function newToken(): string
    {
        return 'scim_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param list<string> $domains */
    private static function domainsJson(array $domains): string
    {
        return json_encode(array_values(array_unique(array_map('strtolower', $domains))), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function shape(array $row): array
    {
        $domains = json_decode((string) $row['domains'], true);
        $row['domains'] = is_array($domains) ? array_values(array_map('strval', $domains)) : [];
        unset($row['token_hash']);

        return $row;
    }
}
