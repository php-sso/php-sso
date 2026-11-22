<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;
use RuntimeException;

/** PostgreSQL 13 or later. */
final class PostgresDialect implements Dialect
{
    /** The advisory lock that lets one process at a time migrate (the key is arbitrary, "SSOM"). */
    private const LOCK_KEY = 1397051213;

    public function name(): string
    {
        return 'pgsql';
    }

    public function insertIgnore(string $table, array $columns, array $keyColumns): string
    {
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $keyColumns) . ') DO NOTHING';
    }

    public function insertIgnoreSelect(string $table, array $columns, array $keyColumns, string $select): string
    {
        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') ' . $select . ' ON CONFLICT (' . implode(', ', $keyColumns) . ') DO NOTHING';
    }

    public function upsert(string $table, array $columns, array $keyColumns): string
    {
        $update = array_map(static fn (string $c): string => $c . ' = excluded.' . $c, array_values(array_diff($columns, $keyColumns)));

        return 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' ON CONFLICT (' . implode(', ', $keyColumns) . ') DO UPDATE SET ' . implode(', ', $update);
    }

    public function lastInsertId(PDO $pdo, string $table): int
    {
        return (int) $pdo->lastInsertId($table . '_id_seq');
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
        return $column . ' IS NOT DISTINCT FROM ' . $placeholder;
    }

    public function like(string $column, string $placeholder): string
    {
        return $column . ' ILIKE ' . $placeholder . " ESCAPE '\\'";
    }

    public function castText(string $expression): string
    {
        return 'CAST(' . $expression . ' AS TEXT)';
    }

    public function castInt(string $expression): string
    {
        return 'CAST(' . $expression . ' AS BIGINT)';
    }

    public function deferForeignKeys(PDO $pdo): void
    {
        $pdo->exec('SET CONSTRAINTS ALL DEFERRED'); // the keys that point at clients are DEFERRABLE (see PostgresSchema)
    }

    public function restoreForeignKeys(PDO $pdo): void
    {
    }

    public function classify(PDOException $e): ?string
    {
        return match ((string) $e->getCode()) {
            '23505' => 'duplicate',
            '23503' => 'reference',
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
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (LOWER(email))');
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function beginMigration(PDO $pdo): void
    {
        $pdo->beginTransaction();
    }

    public function commitMigration(PDO $pdo): void
    {
        $pdo->commit();
    }

    public function rollbackMigration(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public function lock(PDO $pdo, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (true) { // an advisory lock belongs to the database, and is held until unlock() or the connection ends
            if ($pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn() === true) { // PDO gives a boolean
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Could not get the migration lock within {$seconds} seconds: another process is migrating.");
            }
            usleep(100_000);
        }
    }

    public function unlock(PDO $pdo): void
    {
        $pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')')->fetchColumn();
    }

    public function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?');
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?');
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function createMigrationsTable(PDO $pdo, string $table): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS "' . $table . '" (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at BIGINT NOT NULL)');
    }
}
