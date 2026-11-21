<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PDOException;
use RuntimeException;

/** PostgreSQL 13 or later. */
final class PostgresDialect implements Dialect
{
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
}
