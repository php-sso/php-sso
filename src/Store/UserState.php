<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What a user record says about itself, whatever stores it. */
final class UserState
{
    /** @param array<string, mixed> $user the user row */
    public static function isLocked(array $user): bool
    {
        return ($user['locked_at'] ?? null) !== null;
    }
}
