<?php

declare(strict_types=1);

namespace PhpSso\Audit;

use PhpSso\Store\StorageFactory;
use PhpSso\Store\StorageOptions;
use Throwable;

/** The audit log for the command line tools (`bin/keys`, `bin/backup`, `bin/restore`): what they do is recorded as `cli:<the user who ran them>`. */
final class CliAudit
{
    /** @param array<string, mixed> $config the app's config */
    public static function open(array $config): ?Audit
    {
        try {
            return new Audit(StorageFactory::create($config['storage'], StorageOptions::fromConfig($config))->auditLog(), (bool) $config['audit']['stream']);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Warning: the audit log cannot be reached, so this is not recorded in it (' . $e->getMessage() . ").\n");

            return null;
        }
    }

    /** `cli:` and the name of the user running the command (not the owner of the file); the numeric id when that user has no name. */
    public static function actor(): string
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $name = $uid !== null && function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? null) : null;

        return 'cli:' . ($name ?? (getenv('USER') ?: ($uid !== null ? 'uid-' . $uid : 'unknown')));
    }
}
