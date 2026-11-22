<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/** A database connection with the dialect that goes with it. */
final class Connection
{
    public function __construct(public readonly PDO $pdo, public readonly Dialect $dialect)
    {
    }

    /** Opens (and creates, or brings up to date) a SQLite database; `:memory:` for a throwaway one. */
    public static function sqlite(string $path): self
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
        (new SqliteSchema($pdo))->install();

        return new self($pdo, $dialect);
    }

    /** Opens a MySQL database (8.0.19 or later) and creates the tables that are missing. */
    public static function mysql(string $host, int $port, string $database, string $username, string $password): self
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
        (new MysqlSchema($pdo))->install();

        return new self($pdo, $dialect);
    }

    /** Opens a PostgreSQL database (13 or later) and creates the tables that are missing. */
    public static function postgres(string $host, int $port, string $database, string $username, string $password): self
    {
        $dialect = new PostgresDialect();
        $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database), $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [SqlStatement::class, [$dialect]],
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        (new PostgresSchema($pdo))->install();

        return new self($pdo, $dialect);
    }

    public function enforceUniqueEmail(bool $unique): void
    {
        $this->dialect->enforceUniqueEmail($this->pdo, $unique);
    }
}
