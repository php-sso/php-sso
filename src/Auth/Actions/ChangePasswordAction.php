<?php

declare(strict_types=1);

namespace PhpSso\Auth\Actions;

use PhpSso\Store\Sessions;
use PhpSso\Store\Users;

/**
 * An administrator flagged this person to choose their own password (usually because the administrator chose the
 * first one). A person without a local password is not asked: they sign in some other way.
 */
final class ChangePasswordAction implements RequiredAction
{
    public const MIN_LENGTH = 8;

    public function __construct(private Users $users, private Sessions $sessions)
    {
    }

    public function key(): string
    {
        return 'change_password';
    }

    public function applies(array $user): bool
    {
        return (bool) ($user['must_change_password'] ?? false) && is_string($user['password_hash'] ?? null);
    }

    public function page(array $user): array
    {
        return ['template' => 'password_change', 'vars' => ['username' => $user['username'], 'min_length' => self::MIN_LENGTH]];
    }

    public function submit(array $user, array $body, string $ip): ?string
    {
        $new = (string) ($body['new_password'] ?? '');
        if (mb_strlen($new) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (!hash_equals($new, (string) ($body['confirm_password'] ?? ''))) {
            return 'The two passwords do not match.';
        }
        if (is_string($user['password_hash'] ?? null) && password_verify($new, $user['password_hash'])) {
            return 'Choose a password that is different from the one you have now.';
        }

        $this->users->setPassword($user['sub'], $new); // also clears the flag
        // A password someone else chose may be known to them: other sessions of this person end, as when changing it in the account page.
        $this->sessions->endOthers($user['sub'], (string) ($_SESSION['sid'] ?? ''));

        return null;
    }
}
