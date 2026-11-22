<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What a storage needs to know that is not about where the data lives. */
final readonly class StorageOptions
{
    /**
     * @param int $sessionIdleSeconds how long a browser session may sit unused before it counts as ended
     * @param list<string> $builtinScopes the names of the scopes whose code is fixed, from the config
     * @param int $auditRetentionSeconds how long audit log entries are kept (0: for ever)
     */
    public function __construct(public int $sessionIdleSeconds, public array $builtinScopes, public int $auditRetentionSeconds = 0)
    {
    }

    /** @param array<string, mixed> $config the app's config (config/config.php) */
    public static function fromConfig(array $config): self
    {
        return new self((int) $config['session_idle_seconds'], array_keys($config['scopes']), (int) $config['audit']['retention_seconds']);
    }
}
