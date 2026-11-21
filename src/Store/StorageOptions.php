<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What a storage needs to know that is not about where the data lives. */
final readonly class StorageOptions
{
    /**
     * @param int $sessionIdleSeconds how long a browser session may sit unused before it counts as ended
     * @param list<string> $builtinScopes the names of the scopes whose code is fixed, from the config
     */
    public function __construct(public int $sessionIdleSeconds, public array $builtinScopes)
    {
    }
}
