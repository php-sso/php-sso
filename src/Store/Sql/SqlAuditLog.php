<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\AuditLog;

final class SqlAuditLog implements AuditLog
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function record(array $entry, ?int $now = null): void
    {
        $detail = $entry['detail'] ?? [];
        $this->pdo->prepare('INSERT INTO audit_log (at, actor_sub, actor, ip, action, target_type, target, target_label, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $now ?? time(),
            self::cut($entry['actor_sub'] ?? null, 255),
            self::cut($entry['actor'], 255),
            self::cut($entry['ip'] ?? '', 64),
            self::cut($entry['action'], 100),
            self::cut($entry['target_type'] ?? null, 50),
            self::cut($entry['target'] ?? null, 255),
            self::cut($entry['target_label'] ?? null, 500),
            $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }

    public function search(array $filter, int $limit, int $offset = 0): array
    {
        [$where, $params] = $this->where($filter);
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log' . $where . ' ORDER BY at DESC, id DESC LIMIT :l OFFSET :o');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':l', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':o', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'at' => (int) $row['at'],
            'actor_sub' => $row['actor_sub'],
            'actor' => (string) $row['actor'],
            'ip' => (string) $row['ip'],
            'action' => (string) $row['action'],
            'target_type' => $row['target_type'],
            'target' => $row['target'],
            'target_label' => $row['target_label'],
            'detail' => is_string($row['detail']) ? (json_decode($row['detail'], true) ?: []) : [],
        ], $stmt->fetchAll());
    }

    public function count(array $filter): int
    {
        [$where, $params] = $this->where($filter);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log' . $where);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function deleteOlderThan(int $time): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM audit_log WHERE at < ?');
        $stmt->execute([$time]);

        return $stmt->rowCount();
    }

    /** @return array{string, array<string, string|int>} */
    private function where(array $filter): array
    {
        $conditions = [];
        $params = [];
        if (($filter['action'] ?? '') !== '') {
            $conditions[] = $this->dialect->like('action', ':action');
            $params[':action'] = addcslashes((string) $filter['action'], '%_\\') . '%';
        }
        if (($filter['actor'] ?? '') !== '') {
            $conditions[] = '(' . $this->dialect->like('actor', ':actor') . ' OR actor_sub = :actor_sub)';
            $params[':actor'] = '%' . addcslashes((string) $filter['actor'], '%_\\') . '%';
            $params[':actor_sub'] = (string) $filter['actor'];
        }
        if (($filter['target'] ?? '') !== '') {
            $conditions[] = '(' . $this->dialect->like('target', ':target') . ' OR ' . $this->dialect->like('target_label', ':target_label') . ')';
            $like = '%' . addcslashes((string) $filter['target'], '%_\\') . '%';
            $params[':target'] = $like;
            $params[':target_label'] = $like;
        }
        if (isset($filter['since'])) {
            $conditions[] = 'at >= :since';
            $params[':since'] = (int) $filter['since'];
        }
        if (isset($filter['until'])) {
            $conditions[] = 'at <= :until';
            $params[':until'] = (int) $filter['until'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
