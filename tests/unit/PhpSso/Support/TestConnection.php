<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PDO;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migrator;

/**
 * An empty database for a test. SSO_TEST_DATABASE picks the backend: unset or `sqlite` (a fresh in-memory database), or a URL
 * such as `mysql://sso:sso@mysql/sso_test` or `pgsql://sso:sso@postgres/sso_test` (an existing, empty database that the tests
 * own: the tables are created once and emptied between tests, except `schema_migrations`, which keeps saying the schema is installed).
 */
final class TestConnection
{
    public static function open(): Connection
    {
        $url = getenv('SSO_TEST_DATABASE') ?: 'sqlite';
        if ($url === 'sqlite') {
            return Connection::sqlite(':memory:');
        }

        $connection = self::connect($url);
        self::empty($connection);
        $connection->enforceUniqueEmail(false); // an index a test asked for must not outlive it

        return $connection;
    }

    /**
     * Two connections to the same database, for a test of what one process does to another (a lock). SQLite gets a file in
     * the temporary directory, and the second connection does not wait for a lock. Call the closure at the end of the test.
     *
     * @return array{Connection, Connection, \Closure} the two, and what removes the file
     */
    public static function openPair(): array
    {
        $url = getenv('SSO_TEST_DATABASE') ?: 'sqlite';
        if ($url === 'sqlite') {
            $path = sys_get_temp_dir() . '/sso-pair-test-' . bin2hex(random_bytes(4)) . '.sqlite';
            $first = Connection::sqlite($path);
            $second = Connection::sqlite($path);
            $second->pdo->exec('PRAGMA busy_timeout = 0');

            return [$first, $second, static function () use ($path): void {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($path . $suffix);
                }
            }];
        }

        return [self::connect($url), self::connect($url), static function (): void {
        }];
    }

    private static function connect(string $url): Connection
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('SSO_TEST_DATABASE must be `sqlite` or a URL like mysql://user:password@host/database.');
        }
        $arguments = [$parts['host'], $parts['port'] ?? ($parts['scheme'] === 'mysql' ? 3306 : 5432), ltrim($parts['path'], '/'), urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? '')];

        return match ($parts['scheme']) {
            'mysql' => Connection::mysql(...$arguments),
            'pgsql' => Connection::postgres(...$arguments),
            default => throw new \InvalidArgumentException('Unknown database "' . $parts['scheme'] . '" in SSO_TEST_DATABASE.'),
        };
    }

    /** Empties every table that has rows (and, for MySQL and PostgreSQL, starts its ids over). */
    private static function empty(Connection $connection): void
    {
        $pdo = $connection->pdo;
        $mysql = $connection->dialect->name() === 'mysql';
        $tables = $pdo->query($mysql
            ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
            : "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        // schema_migrations stays as it is: the schema is installed once and is still there, so it must still say so.
        $tables = array_values(array_filter($tables, static fn (string $t): bool => $t !== Migrator::TABLE));
        $used = array_values(array_filter($tables, static fn (string $t): bool => $pdo->query('SELECT 1 FROM "' . $t . '" LIMIT 1')->fetchColumn() !== false));
        if ($used === []) {
            return;
        }

        if ($mysql) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($used as $table) {
                $pdo->exec('TRUNCATE TABLE "' . $table . '"');
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $pdo->exec('TRUNCATE ' . implode(', ', array_map(static fn (string $t): string => '"' . $t . '"', $used)) . ' RESTART IDENTITY CASCADE');
        }
    }
}
