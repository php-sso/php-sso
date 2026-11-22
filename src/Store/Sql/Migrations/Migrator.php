<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use LogicException;
use PDOException;
use PhpSso\Store\Sql\Connection;
use Throwable;

/**
 * Brings a database up to the latest schema by running the migrations that have not run, and records each one in the
 * `schema_migrations` table.
 *
 * Where the database can roll back DDL (SQLite, PostgreSQL) a migration and its record are one transaction. MySQL cannot:
 * the steps run, and the record is written only after the last one succeeded, so a failed migration is retried (from its
 * first step) the next time, and its steps must be safe to run again. One process at a time migrates (a database lock),
 * and what ran meanwhile is read again once the lock is held.
 */
final class Migrator
{
    public const TABLE = 'schema_migrations';

    private const LOCK_SECONDS = 60;

    /** @var list<Migration> */
    private array $migrations;

    /**
     * @param list<Migration>|null $migrations null for the app's own ({@see Migrations::all()}); tests pass others
     * @param string $table where the versions are recorded
     */
    public function __construct(private Connection $db, ?array $migrations = null, private string $table = self::TABLE)
    {
        $this->migrations = $migrations ?? Migrations::all();
        $previous = null;
        foreach ($this->migrations as $migration) {
            if (preg_match('/^\d{4,}$/', $migration->id()) !== 1) {
                throw new LogicException('A migration id is a zero padded number such as 0001, not "' . $migration->id() . '".');
            }
            if ($previous !== null && strcmp($migration->id(), $previous) <= 0) {
                throw new LogicException("Migration {$migration->id()} must come after {$previous} (ids are unique and ascending).");
            }
            $previous = $migration->id();
        }
    }

    /**
     * The versions that ran, with when (a unix time). One cheap query; empty when the table is not there yet.
     *
     * @return array<string, int>
     */
    public function applied(): array
    {
        try {
            $rows = $this->db->pdo->query('SELECT version, applied_at FROM "' . $this->table . '"')->fetchAll();
        } catch (PDOException $e) {
            if ($this->db->dialect->tableExists($this->db->pdo, $this->table)) {
                throw $e;
            }

            return [];
        }

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['version']] = (int) $row['applied_at'];
        }

        return $applied;
    }

    /** @return list<Migration> the migrations that have not run, in the order they will */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter($this->migrations, static fn (Migration $m): bool => !isset($applied[$m->id()])));
    }

    /**
     * Every migration with when it ran (null: pending).
     *
     * @return list<array{migration: Migration, appliedAt: int|null}>
     */
    public function status(): array
    {
        $applied = $this->applied();

        return array_map(static fn (Migration $m): array => ['migration' => $m, 'appliedAt' => $applied[$m->id()] ?? null], $this->migrations);
    }

    /**
     * Runs what is pending, under the lock. Costs one query when there is nothing to do.
     *
     * @param (callable(Migration): void)|null $starting called before each migration runs
     * @return list<Migration> the migrations that ran
     */
    public function migrate(?callable $starting = null): array
    {
        if ($this->pending() === []) {
            return [];
        }

        $pdo = $this->db->pdo;
        $dialect = $this->db->dialect;
        $dialect->lock($pdo, self::LOCK_SECONDS);
        try {
            $dialect->createMigrationsTable($pdo, $this->table);
            $applied = $this->applied(); // another process may have finished the work while this one waited for the lock
            $ran = [];
            foreach ($this->migrations as $migration) {
                if (isset($applied[$migration->id()])) {
                    continue;
                }
                if ($starting !== null) {
                    $starting($migration);
                }
                if ($this->apply($migration)) {
                    $ran[] = $migration;
                }
            }

            return $ran;
        } finally {
            $dialect->unlock($pdo);
        }
    }

    /** @return bool whether it ran (false: it had been recorded in the meantime) */
    private function apply(Migration $migration): bool
    {
        $pdo = $this->db->pdo;
        $dialect = $this->db->dialect;
        if (!$dialect->transactionalDdl()) {
            $migration->run($this->db);
            $this->record($migration);

            return true;
        }

        $dialect->beginMigration($pdo);
        try {
            if ($this->recorded($migration)) { // SQLite takes its lock only here, so look once more
                $dialect->rollbackMigration($pdo);

                return false;
            }
            $migration->run($this->db);
            $this->record($migration);
            $dialect->commitMigration($pdo);
        } catch (Throwable $e) {
            $dialect->rollbackMigration($pdo);
            throw $e;
        }

        return true;
    }

    private function recorded(Migration $migration): bool
    {
        $stmt = $this->db->pdo->prepare('SELECT 1 FROM "' . $this->table . '" WHERE version = ?');
        $stmt->execute([$migration->id()]);

        return $stmt->fetchColumn() !== false;
    }

    private function record(Migration $migration): void
    {
        $this->db->pdo->prepare('INSERT INTO "' . $this->table . '" (version, applied_at) VALUES (?, ?)')->execute([$migration->id(), time()]);
    }
}
