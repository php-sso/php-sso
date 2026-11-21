<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Settings an administrator changes in the admin panel. Each has a text value; JSON is fine for structured ones. */
interface Settings
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;
}
