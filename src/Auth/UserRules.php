<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Identifier\IdentifierResolverFactory;
use PhpSso\Store\Users;

/** What the way people sign in (`login.identifier`) asks of usernames and email addresses. */
final class UserRules
{
    public function __construct(private Users $users, private string $identifierType)
    {
    }

    /** Which characters a username may use, for the hint under the field. */
    public function usernameHint(): string
    {
        return $this->identifierType === 'username_or_email'
            ? 'Used to sign in. Letters, digits and . _ - (no @, so it cannot look like an email address)'
            : 'Used to sign in. Letters, digits and . _ @ -';
    }

    /** A reason this username cannot be given to someone new, or null. Applies to new and changed usernames only. */
    public function usernameError(string $username): ?string
    {
        // With "username or email", text containing an `@` is looked up as an email address first.
        if ($this->identifierType === 'username_or_email' && str_contains($username, '@')) {
            return 'Usernames cannot contain @ when people sign in with a username or an email address.';
        }

        return null;
    }

    /** A reason this email address cannot be used by the person `$exceptSub`, or null. */
    public function emailError(string $email, ?string $exceptSub): ?string
    {
        if (IdentifierResolverFactory::usesEmail($this->identifierType) && $this->users->emailTaken($email, $exceptSub)) {
            return 'This email address is already in use.';
        }

        return null;
    }
}
