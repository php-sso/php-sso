<?php

declare(strict_types=1);

namespace PhpSso\Store;

use InvalidArgumentException;
use PhpSso\Store\Sqlite\SqliteStorage;

/** Builds the storage the `storage` config asks for. A new backend is one more `case`. */
final class StorageFactory
{
    /** @param array<string, mixed> $config e.g. `['driver' => 'sqlite', 'path' => '/var/lib/sso/sso.sqlite']` */
    public static function create(array $config, StorageOptions $options): Storage
    {
        $driver = $config['driver'] ?? 'sqlite';

        return match ($driver) {
            'sqlite' => SqliteStorage::open(is_string($config['path'] ?? null) ? $config['path'] : throw new InvalidArgumentException('storage.path must be set for the sqlite driver.'), $options),
            default => throw new InvalidArgumentException('Unknown storage driver "' . (is_string($driver) ? $driver : gettype($driver)) . '". Available: sqlite.'),
        };
    }
}
