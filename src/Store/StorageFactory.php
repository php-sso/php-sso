<?php

declare(strict_types=1);

namespace PhpSso\Store;

use InvalidArgumentException;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlStorage;

/** Builds the storage the `storage` config asks for: `sqlite` (path), `mysql` or `pgsql` (host, port, database, username, password). */
final class StorageFactory
{
    /** @param array<string, mixed> $config e.g. `['driver' => 'sqlite', 'path' => '/var/lib/sso/sso.sqlite']`; `auto_migrate` (default true) says whether to bring the schema up to date */
    public static function create(array $config, StorageOptions $options): Storage
    {
        return new SqlStorage(self::connect($config), $options);
    }

    /**
     * Opens the database. It is migrated to the latest schema unless `$migrate` (default: the config's `auto_migrate`, which
     * is true when absent) is false: then it is opened as it is, so `bin/migrate` can look at it and migrate on purpose.
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config, ?bool $migrate = null): Connection
    {
        $driver = $config['driver'] ?? 'sqlite';
        $migrate ??= (bool) ($config['auto_migrate'] ?? true);

        return match ($driver) {
            'sqlite' => Connection::sqlite(self::string($config, 'path'), $migrate),
            'mysql' => Connection::mysql(self::string($config, 'host'), (int) ($config['port'] ?: 3306), self::string($config, 'database'), self::string($config, 'username'), (string) ($config['password'] ?? ''), $migrate),
            'pgsql' => Connection::postgres(self::string($config, 'host'), (int) ($config['port'] ?: 5432), self::string($config, 'database'), self::string($config, 'username'), (string) ($config['password'] ?? ''), $migrate),
            default => throw new InvalidArgumentException('Unknown storage driver "' . (is_string($driver) ? $driver : gettype($driver)) . '". Available: sqlite, mysql, pgsql.'),
        };
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key): string
    {
        return is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : throw new InvalidArgumentException("storage.{$key} must be set for the " . ($config['driver'] ?? 'sqlite') . " driver.");
    }
}
