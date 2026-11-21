<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\Auth\AccountPolicy;
use PhpSso\Auth\UserRules;
use PhpSso\ScimServer\Account;
use PhpSso\ScimServer\Contract\Accounts;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;

/** The application's users, as the SCIM server sees them. Sign-in names, locking and sessions are decided here. */
final class UserAccounts implements Accounts
{
    public function __construct(
        private Users $users,
        private Sessions $sessions,
        private UserRules $rules,
        private AccountPolicy $policy,
    ) {
    }

    public function findByEmail(string $email): ?Account
    {
        $user = $this->users->findByEmail($email);

        return $user === null ? null : new Account((string) $user['sub'], (bool) $user['is_admin']);
    }

    public function emailError(string $email, ?string $exceptSub): ?string
    {
        return $this->rules->emailError($email, $exceptSub);
    }

    public function emailPolicyError(string $email): ?string
    {
        return $this->policy->domainAllowed($email) ? null : 'Accounts for that email domain are not allowed here.';
    }

    public function create(string $name, string $email): string
    {
        return $this->users->create([
            'username' => $this->freeUsername($email),
            'password' => null,
            'name' => $name,
            'email' => $email,
            'email_verified' => true,
            'is_admin' => false,
        ]);
    }

    public function updateProfile(string $sub, string $name, string $email): void
    {
        $this->users->updateProfile($sub, $name, $email, true);
    }

    public function setActive(string $sub, bool $active): void
    {
        $this->users->setLocked($sub, !$active);
        if (!$active) {
            $this->sessions->endAllForUser($sub);
        }
    }

    public function delete(string $sub): void
    {
        $this->sessions->endAllForUser($sub); // revokes tokens and notifies apps before the account disappears
        $this->users->delete($sub);
    }

    public function displayName(string $sub): ?string
    {
        $user = $this->users->find($sub);

        return $user === null ? null : (string) $user['name'];
    }

    /** A username for sign-in, from the address: the part before the @ (adding a number when it is taken). */
    private function freeUsername(string $email): string
    {
        $base = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($email, '@', true))) ?? '', 0, 50);
        $base = strlen($base) < 2 ? 'user' : $base;
        $username = $base;
        for ($i = 2; $this->users->findByUsername($username) !== null; $i++) {
            $username = $base . $i;
        }

        return $username;
    }
}
