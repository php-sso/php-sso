<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

use PhpSso\Store\Users;

/** People sign in with their username. Usernames are compared without regard to case. */
final class UsernameResolver implements IdentifierResolver
{
    public function __construct(private Users $users)
    {
    }

    public function normalize(string $input): string
    {
        return $input;
    }

    public function find(string $input): ?array
    {
        return $this->users->findByUsername($this->normalize($input));
    }

    public function domain(string $input): ?string
    {
        return null;
    }

    public function label(): string
    {
        return 'Username';
    }

    public function inputType(): string
    {
        return 'text';
    }
}
