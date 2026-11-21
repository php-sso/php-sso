<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

use PhpSso\Store\Users;

/** People sign in with their email address. Addresses are compared without regard to case. */
final class EmailResolver implements IdentifierResolver
{
    public function __construct(private Users $users)
    {
    }

    public function normalize(string $input): string
    {
        return EmailAddress::normalize($input);
    }

    public function find(string $input): ?array
    {
        return $this->users->findByEmail($this->normalize($input));
    }

    public function domain(string $input): ?string
    {
        return EmailAddress::domain($input);
    }

    public function label(): string
    {
        return 'Email address';
    }

    public function inputType(): string
    {
        return 'email';
    }
}
