<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

use PhpSso\Store\Users;
use InvalidArgumentException;

/** Builds the resolver named in the `login.identifier` setting. */
final class IdentifierResolverFactory
{
    public const TYPES = ['username', 'email', 'username_or_email'];

    /** Whether people are found by email address, which then has to be different for everyone. */
    public static function usesEmail(string $type): bool
    {
        return $type !== 'username';
    }

    public static function create(string $type, Users $users): IdentifierResolver
    {
        return match ($type) {
            'username' => new UsernameResolver($users),
            'email' => new EmailResolver($users),
            'username_or_email' => new UsernameOrEmailResolver($users),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown login identifier "%s". Use one of: %s.',
                $type,
                implode(', ', self::TYPES),
            )),
        };
    }
}
