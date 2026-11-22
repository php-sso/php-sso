<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;
use PhpSso\Store\Sql\Migrations\Migrator;

/** A database connection with the dialect that goes with it. */
final class Connection
{
    public function __construct(public readonly PDO $pdo, public readonly Dialect $dialect)
    {
    }

    /**
     * Opens a SQLite database; `:memory:` for a throwaway one. With `$migrate` (the default) it is created, or brought up to
     * date, first; without, it is opened as it is (see {@see migrator()}).
     */
    public static function sqlite(string $path, bool $migrate = true): self
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $dialect = new SqliteDialect();
        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [SqlStatement::class, [$dialect]],
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000'); // wait for another request's write instead of failing at once
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL'); // readers and the writer do not block each other
            $pdo->exec('PRAGMA synchronous = NORMAL'); // safe with WAL: a power cut can lose the last moments, not corrupt the file
        }

        return (new self($pdo, $dialect))->migratedIf($migrate);
    }

    /** Opens a MySQL database (8.0.19 or later), and brings it up to date unless `$migrate` is false. */
    public static function mysql(string $host, int $port, string $database, string $username, string $password, bool $migrate = true): self
    {
        $dialect = new MysqlDialect();
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database), $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [SqlStatement::class, [$dialect]],
            PDO::ATTR_EMULATE_PREPARES => true, // a named parameter may then be used twice, and a limit may be bound
            PDO::MYSQL_ATTR_FOUND_ROWS => true, // rowCount() is how many rows matched, as elsewhere, not how many changed
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_bin, SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,ANSI_QUOTES,PIPES_AS_CONCAT'",
        ]);

        return (new self($pdo, $dialect))->migratedIf($migrate);
    }

    /** Opens a PostgreSQL database (13 or later), and brings it up to date unless `$migrate` is false. */
    public static function postgres(string $host, int $port, string $database, string $username, string $password, bool $migrate = true): self
    {
        $dialect = new PostgresDialect();
        $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database), $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [SqlStatement::class, [$dialect]],
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);

        return (new self($pdo, $dialect))->migratedIf($migrate);
    }

    public function enforceUniqueEmail(bool $unique): void
    {
        $this->dialect->enforceUniqueEmail($this->pdo, $unique);
    }

    /** The migrations of this database: what ran, what is pending, and running them. */
    public function migrator(): Migrator
    {
        return new Migrator($this);
    }

    public function tableExists(string $table): bool
    {
        return $this->dialect->tableExists($this->pdo, $table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return $this->dialect->columnExists($this->pdo, $table, $column);
    }

    public function indexExists(string $table, string $index): bool
    {
        return $this->dialect->indexExists($this->pdo, $table, $index);
    }

    /** Up to date costs one query (the versions that ran); only a database that is behind runs any DDL. */
    private function migratedIf(bool $migrate): self
    {
        if ($migrate) {
            $this->migrator()->migrate();
        }

        return $this;
    }
}
