<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Store\Clients;
use PhpSso\Store\Groups;
use PhpSso\Store\Roles;
use PhpSso\Store\Users;

/** Fills an empty database with the `seed` section of the config, so a fresh start is usable. */
final class Seeder
{
    public function __construct(private Users $users, private Groups $groups, private Clients $clients, private Roles $roles)
    {
    }

    /** @return string|null why a password may not be used for a first administrator, or null when it may */
    private static function weakness(string $password): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'is too short: use at least 12 characters.';
        }
        if (count(array_unique(mb_str_split($password))) < 4) {
            return 'is too simple: it repeats the same few characters.';
        }

        return null;
    }

    public function seed(array $seed): void
    {
        if ($this->users->count() > 0) {
            return;
        }

        foreach ($seed['users'] as $username => $u) {
            if ($u['password'] === '') {
                throw new \RuntimeException("The database is empty, so the first user ({$username}) must be created. Set ADMIN_PASSWORD (and start again).");
            }
            if (($seed['check_passwords'] ?? false) && !preg_match('/^[A-Za-z0-9_.@-]{2,64}$/', (string) $username)) {
                throw new \RuntimeException("The username of the first user, ADMIN_USERNAME ({$username}), must be 2 to 64 letters, digits or _ . @ -");
            }
            if (($seed['check_passwords'] ?? false) && ($weakness = self::weakness($u['password'])) !== null) {
                throw new \RuntimeException("The password for the first user ({$username}), ADMIN_PASSWORD, {$weakness}");
            }
        }

        $groupIds = [];
        foreach ($seed['groups'] as $name => $description) {
            $groupIds[$name] = $this->groups->create($name, $description);
        }

        $subs = [];
        foreach ($seed['users'] as $username => $u) {
            $sub = $subs[$username] = $this->users->create([
                'username' => $username,
                'password' => $u['password'],
                'name' => $u['name'],
                'email' => $u['email'],
                'email_verified' => true,
                'is_admin' => $u['is_admin'] ?? false,
            ]);
            $this->users->setGroups($sub, array_map(static fn (string $g) => $groupIds[$g], $u['groups'] ?? []));
        }

        foreach ($seed['clients'] as $clientId => $c) {
            $this->clients->insert([
                'client_id' => $clientId,
                'name' => $c['name'],
                'secret_hash' => $c['secret'] === null ? null : password_hash($c['secret'], PASSWORD_DEFAULT),
                'confidential' => $c['secret'] !== null,
                'redirect_uris' => $c['redirect_uris'],
                'grants' => $c['grants'],
                'backchannel_logout_uri' => $c['backchannel_logout_uri'] ?? null,
                'post_logout_redirect_uris' => $c['post_logout_redirect_uris'] ?? [],
            ]);
        }

        foreach ($seed['roles'] ?? [] as $name => $role) {
            // `clients`: the client ids that receive the role; leave it out for a role every client receives.
            $roleId = $this->roles->create($name, $role['description'] ?? '', !isset($role['clients']), $role['clients'] ?? []);
            foreach ($role['groups'] ?? [] as $group) {
                $this->roles->assignGroup($roleId, $groupIds[$group]);
            }
            foreach ($role['users'] ?? [] as $username) {
                $this->roles->assignUser($roleId, $subs[$username]);
            }
        }
    }
}
