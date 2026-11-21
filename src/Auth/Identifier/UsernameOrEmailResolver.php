<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

use PhpSso\Store\Users;

/**
 * People sign in with either. Input with an `@` is looked up as an email address first and, when no one has that
 * address, as a username (so an older username that contains an `@` keeps working). Input without an `@` is a username.
 */
final class UsernameOrEmailResolver implements IdentifierResolver
{
    public function __construct(private Users $users)
    {
    }

    public function normalize(string $input): string
    {
        return str_contains($input, '@') ? EmailAddress::normalize($input) : $input;
    }

    public function find(string $input): ?array
    {
        $normalized = $this->normalize($input);
        if (str_contains($normalized, '@')) {
            return $this->users->findByEmail($normalized) ?? $this->users->findByUsername($input);
        }

        return $this->users->findByUsername($normalized);
    }

    public function domain(string $input): ?string
    {
        return EmailAddress::domain($input);
    }

    public function label(): string
    {
        return 'Username or email';
    }

    public function inputType(): string
    {
        return 'text';
    }
}
