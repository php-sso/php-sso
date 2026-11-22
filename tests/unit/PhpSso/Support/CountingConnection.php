<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PDO;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\MysqlDialect;
use PhpSso\Store\Sql\PostgresDialect;
use PhpSso\Store\Sql\SqliteDialect;

/** A connection to the database under test (see {@see TestConnection}) that remembers every statement it is asked to run. */
final class CountingConnection extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public static function open(): Connection
    {
        $url = getenv('SSO_TEST_DATABASE') ?: 'sqlite';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        if ($url === 'sqlite') {
            $pdo = new self('sqlite::memory:', options: $options);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return new Connection($pdo, new SqliteDialect());
        }

        $parts = parse_url($url);
        $user = urldecode($parts['user'] ?? '');
        $password = urldecode($parts['pass'] ?? '');
        $database = ltrim($parts['path'] ?? '', '/');
        if ($parts['scheme'] === 'mysql') {
            $options += [PDO::ATTR_EMULATE_PREPARES => true, PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_bin, SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,ANSI_QUOTES,PIPES_AS_CONCAT'"];

            return new Connection(new self(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $parts['host'], $parts['port'] ?? 3306, $database), $user, $password, $options), new MysqlDialect());
        }

        $options += [PDO::ATTR_EMULATE_PREPARES => true];

        return new Connection(new self(sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $database), $user, $password, $options), new PostgresDialect());
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->statements[] = $query;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }
}
