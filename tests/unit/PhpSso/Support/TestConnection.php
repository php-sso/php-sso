<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PDO;
use PhpSso\Store\Sql\Connection;

/**
 * An empty database for a test. SSO_TEST_DATABASE picks the backend: unset or `sqlite` (a fresh in-memory database), or a URL
 * such as `mysql://sso:sso@mysql/sso_test` or `pgsql://sso:sso@postgres/sso_test` (an existing, empty database that the tests
 * own: the tables are created once and emptied between tests).
 */
final class TestConnection
{
    public static function open(): Connection
    {
        $url = getenv('SSO_TEST_DATABASE') ?: 'sqlite';
        if ($url === 'sqlite') {
            return Connection::sqlite(':memory:');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
            throw new \InvalidArgumentException('SSO_TEST_DATABASE must be `sqlite` or a URL like mysql://user:password@host/database.');
        }
        $arguments = [$parts['host'], $parts['port'] ?? ($parts['scheme'] === 'mysql' ? 3306 : 5432), ltrim($parts['path'], '/'), urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? '')];
        $connection = match ($parts['scheme']) {
            'mysql' => Connection::mysql(...$arguments),
            'pgsql' => Connection::postgres(...$arguments),
            default => throw new \InvalidArgumentException('Unknown database "' . $parts['scheme'] . '" in SSO_TEST_DATABASE.'),
        };
        self::empty($connection);
        $connection->enforceUniqueEmail(false); // an index a test asked for must not outlive it

        return $connection;
    }

    /** Empties every table that has rows (and, for MySQL and PostgreSQL, starts its ids over). */
    private static function empty(Connection $connection): void
    {
        $pdo = $connection->pdo;
        $mysql = $connection->dialect->name() === 'mysql';
        $tables = $pdo->query($mysql
            ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
            : "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
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
