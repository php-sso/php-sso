<?php

declare(strict_types=1);

namespace PhpSso\Store;

use InvalidArgumentException;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlStorage;

/** Builds the storage the `storage` config asks for: `sqlite` (path), `mysql` or `pgsql` (host, port, database, username, password). */
final class StorageFactory
{
    /** @param array<string, mixed> $config e.g. `['driver' => 'sqlite', 'path' => '/var/lib/sso/sso.sqlite']` */
    public static function create(array $config, StorageOptions $options): Storage
    {
        $driver = $config['driver'] ?? 'sqlite';

        return match ($driver) {
            'sqlite' => new SqlStorage(Connection::sqlite(self::string($config, 'path')), $options),
            'mysql' => new SqlStorage(Connection::mysql(self::string($config, 'host'), (int) ($config['port'] ?? 3306), self::string($config, 'database'), self::string($config, 'username'), (string) ($config['password'] ?? '')), $options),
            'pgsql' => new SqlStorage(Connection::postgres(self::string($config, 'host'), (int) ($config['port'] ?? 5432), self::string($config, 'database'), self::string($config, 'username'), (string) ($config['password'] ?? '')), $options),
            default => throw new InvalidArgumentException('Unknown storage driver "' . (is_string($driver) ? $driver : gettype($driver)) . '". Available: sqlite, mysql, pgsql.'),
        };
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key): string
    {
        return is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : throw new InvalidArgumentException("storage.{$key} must be set for the " . ($config['driver'] ?? 'sqlite') . " driver.");
    }
}
