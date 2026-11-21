<?php

declare(strict_types=1);

namespace PhpSso\Store;

use PhpSso\Database;
use PDO;

/**
 * Allow and deny rules for the IP addresses people may sign in from. Each rule is an address or CIDR range.
 * A rule belongs to one client, or (client id null) applies to everything.
 */
class IpRules
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    /** @return array<int, array{id: int, client_id: ?string, type: string, cidr: string, note: string, created_at: int}> */
    public function all(?string $clientId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ip_rules WHERE client_id IS :c ORDER BY type, id');
        $stmt->execute([':c' => $clientId]);

        return $stmt->fetchAll();
    }

    /** @return string[] ranges of the given type ('allow' or 'deny') */
    public function ranges(string $type, ?string $clientId = null): array
    {
        $stmt = $this->pdo->prepare('SELECT cidr FROM ip_rules WHERE type = :t AND client_id IS :c');
        $stmt->execute([':t' => $type, ':c' => $clientId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Whether the client is judged by its own rules only, without the general ones. */
    public function ignoresGeneral(string $clientId): bool
    {
        $stmt = $this->pdo->prepare('SELECT ignore_general_ip_rules FROM clients WHERE client_id = ?');
        $stmt->execute([$clientId]);

        return (bool) $stmt->fetchColumn();
    }

    public function setIgnoresGeneral(string $clientId, bool $ignores): void
    {
        $this->pdo->prepare('UPDATE clients SET ignore_general_ip_rules = ? WHERE client_id = ?')->execute([(int) $ignores, $clientId]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ip_rules WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function exists(string $type, string $cidr, ?string $clientId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ip_rules WHERE type = :t AND cidr = :r AND client_id IS :c');
        $stmt->execute([':t' => $type, ':r' => $cidr, ':c' => $clientId]);

        return (bool) $stmt->fetchColumn();
    }

    public function create(string $type, string $cidr, string $note, ?string $clientId = null): int
    {
        $this->pdo->prepare('INSERT INTO ip_rules (client_id, type, cidr, note, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$clientId, $type, $cidr, $note, time()]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ip_rules WHERE id = ?')->execute([$id]);
    }
}
