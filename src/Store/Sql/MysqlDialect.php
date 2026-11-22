<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;
use RuntimeException;

/** MySQL 8.0.19 or later (the connection runs in ANSI quoting mode, so the stores' `"key"` and `||` mean what they do elsewhere). */
final class MysqlDialect implements Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function insertIgnore(string $table, array $columns, array $keyColumns): string
    {
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $table . '.' . $keyColumns[0] . ' = ' . $table . '.' . $keyColumns[0]; // not INSERT IGNORE: that would hide every other error too
    }

    public function insertIgnoreSelect(string $table, array $columns, array $keyColumns, string $select): string
    {
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') SELECT * FROM (' . $select . ') AS s'
            . ' ON DUPLICATE KEY UPDATE ' . $table . '.' . $keyColumns[0] . ' = ' . $table . '.' . $keyColumns[0];
    }

    public function upsert(string $table, array $columns, array $keyColumns): string
    {
        $update = array_map(static fn (string $c): string => $c . ' = new_row.' . $c, array_values(array_diff($columns, $keyColumns)));

        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ') AS new_row'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update);
    }

    public function lastInsertId(PDO $pdo, string $table): int
    {
        return (int) $pdo->lastInsertId();
    }

    public function ci(string $column): string
    {
        return 'LOWER(' . $column . ')';
    }

    public function ciValue(string $placeholder): string
    {
        return 'LOWER(' . $placeholder . ')';
    }

    public function sameOrNull(string $column, string $placeholder): string
    {
        return $column . ' <=> ' . $placeholder;
    }

    public function like(string $column, string $placeholder): string
    {
        return 'LOWER(' . $column . ') LIKE LOWER(' . $placeholder . ") ESCAPE '\\\\'"; // two backslashes: PDO's own scanner reads one as escaping the quote, and MySQL reads two as one
    }

    public function castText(string $expression): string
    {
        return 'CAST(' . $expression . ' AS CHAR)';
    }

    public function castInt(string $expression): string
    {
        return 'CAST(' . $expression . ' AS SIGNED)';
    }

    public function deferForeignKeys(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); // MySQL cannot defer a check: it is switched off, and back on by restoreForeignKeys()
    }

    public function restoreForeignKeys(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function classify(PDOException $e): ?string
    {
        return match ($e->errorInfo[1] ?? null) {
            1062 => 'duplicate',
            1451, 1452 => 'reference',
            default => null,
        };
    }

    public function enforceUniqueEmail(PDO $pdo, bool $unique): void
    {
        $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'users_email_unique'")->fetchColumn() > 0;
        if (!$unique) {
            if ($exists) {
                $pdo->exec('DROP INDEX users_email_unique ON users');
            }

            return;
        }

        $duplicates = $pdo->query('SELECT LOWER(email) FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1 ORDER BY 1 LIMIT 10')->fetchAll(PDO::FETCH_COLUMN);
        if ($duplicates !== []) {
            throw new RuntimeException('Signing in by email needs a different email address for every user, but these are used more than once: '
                . implode(', ', $duplicates) . '. Give each person their own address, or set SSO_LOGIN_IDENTIFIER=username.');
        }
        if (!$exists) {
            $pdo->exec('CREATE UNIQUE INDEX users_email_unique ON users ((LOWER(email)))');
        }
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    public function beginMigration(PDO $pdo): void
    {
    }

    public function commitMigration(PDO $pdo): void
    {
    }

    public function rollbackMigration(PDO $pdo): void
    {
    }

    public function lock(PDO $pdo, int $seconds): void
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)'); // server wide, so the name carries the database
        $stmt->execute([$this->lockName($pdo), $seconds]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Could not get the migration lock within {$seconds} seconds: another process is migrating.");
        }
    }

    public function unlock(PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$this->lockName($pdo)]);
        $stmt->fetchColumn();
    }

    private function lockName(PDO $pdo): string
    {
        return 'php_sso_migrate_' . md5((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    }

    public function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function createMigrationsTable(PDO $pdo, string $table): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS "' . $table . '" (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
