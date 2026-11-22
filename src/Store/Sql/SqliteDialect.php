<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;
use RuntimeException;

final class SqliteDialect implements Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function insertIgnore(string $table, array $columns, array $keyColumns): string
    {
        return 'INSERT OR IGNORE INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    }

    public function upsert(string $table, array $columns, array $keyColumns): string
    {
        $update = array_map(static fn (string $c): string => $c . ' = excluded.' . $c, array_values(array_diff($columns, $keyColumns)));

        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $keyColumns) . ') DO UPDATE SET ' . implode(', ', $update);
    }

    public function insertIgnoreSelect(string $table, array $columns, array $keyColumns, string $select): string
    {
        return 'INSERT OR IGNORE INTO ' . $table . ' (' . implode(', ', $columns) . ') ' . $select;
    }

    public function lastInsertId(PDO $pdo, string $table): int
    {
        return (int) $pdo->lastInsertId();
    }

    public function ci(string $column): string
    {
        return $column . ' COLLATE NOCASE';
    }

    public function ciValue(string $placeholder): string
    {
        return $placeholder;
    }

    public function sameOrNull(string $column, string $placeholder): string
    {
        return $column . ' IS ' . $placeholder;
    }

    public function like(string $column, string $placeholder): string
    {
        return $column . ' LIKE ' . $placeholder . " ESCAPE '\\'";
    }

    public function castText(string $expression): string
    {
        return 'CAST(' . $expression . ' AS TEXT)';
    }

    public function castInt(string $expression): string
    {
        return 'CAST(' . $expression . ' AS INTEGER)';
    }

    public function deferForeignKeys(PDO $pdo): void
    {
        $pdo->exec('PRAGMA defer_foreign_keys = ON');
    }

    public function restoreForeignKeys(PDO $pdo): void
    {
    }

    public function classify(PDOException $e): ?string
    {
        return match (true) {
            str_contains($e->getMessage(), 'UNIQUE constraint failed') => 'duplicate',
            str_contains($e->getMessage(), 'FOREIGN KEY constraint failed') => 'reference',
            default => null,
        };
    }

    public function enforceUniqueEmail(PDO $pdo, bool $unique): void
    {
        if (!$unique) {
            $pdo->exec('DROP INDEX IF EXISTS users_email_unique');

            return;
        }

        $duplicates = $pdo->query('SELECT LOWER(email) FROM users GROUP BY LOWER(email) HAVING COUNT(*) > 1 ORDER BY 1 LIMIT 10')->fetchAll(PDO::FETCH_COLUMN);
        if ($duplicates !== []) {
            throw new RuntimeException('Signing in by email needs a different email address for every user, but these are used more than once: '
                . implode(', ', $duplicates) . '. Give each person their own address, or set SSO_LOGIN_IDENTIFIER=username.');
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email COLLATE NOCASE)');
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function beginMigration(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF'); // a table rebuild needs it, and SQLite ignores the pragma inside a transaction
        try {
            $pdo->exec('BEGIN IMMEDIATE'); // the write lock: another process that migrates waits here (busy_timeout)
        } catch (\Throwable $e) {
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }
    }

    public function commitMigration(PDO $pdo): void
    {
        if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
            $this->rollbackMigration($pdo);
            throw new RuntimeException('The migration broke a foreign key; nothing was changed.');
        }
        try {
            $pdo->exec('COMMIT');
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function rollbackMigration(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function lock(PDO $pdo, int $seconds): void
    {
        // The lock is the write transaction of beginMigration().
    }

    public function unlock(PDO $pdo): void
    {
    }

    public function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columnExists(PDO $pdo, string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', array_column($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(), 'name')), true);
    }

    public function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?");
        $stmt->execute([$table, $index]);

        return $stmt->fetchColumn() !== false;
    }

    public function createMigrationsTable(PDO $pdo, string $table): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS "' . $table . '" (version TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
    }
}
