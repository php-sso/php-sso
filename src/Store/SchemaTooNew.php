<?php

declare(strict_types=1);

namespace PhpSso\Store;

use RuntimeException;

/**
 * The database was migrated by a newer version of php-sso than this one, so this one does not know its schema: serving from it, or
 * running its older migrations over it, could damage the data. Use the newer version again, or restore a backup made before the upgrade.
 */
final class SchemaTooNew extends RuntimeException
{
    /** @param list<string> $versions the migrations the database has that this version does not know */
    public function __construct(public readonly array $versions)
    {
        parent::__construct(self::describe($versions));
    }

    /** @param list<string> $versions */
    public static function describe(array $versions): string
    {
        return 'The database was migrated by a newer version of php-sso (it has migrations this one does not know: ' . implode(', ', $versions)
            . '). Do not run this version against it: use the newer version again, or restore a backup made before the upgrade.';
    }
}
