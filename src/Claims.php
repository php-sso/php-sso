<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Store\Roles;
use PhpSso\Store\Users;
use PhpSso\OAuthServer\Authorization\ClientSpecificClaims;
use PhpSso\OAuthServer\Authorization\UserClaimsProvider;

/**
 * Maps a user (by `sub`) and the granted scopes to OIDC claims. Used for the ID token and /userinfo.
 * The roles of a person depend on the client, so they are released by clientClaimsFor() and also end up in the
 * access token, where an API behind a client reads them.
 */
final class Claims implements UserClaimsProvider, ClientSpecificClaims
{
    /** The scope that releases the person's roles in the client. */
    public const ROLES_SCOPE = 'roles';

    /** @param array<string, string[]> $scopeClaims scope => claim names */
    public function __construct(private Users $users, private Roles $roles, private array $scopeClaims)
    {
    }

    /** @param string[] $scopes */
    public function clientClaimsFor(string $clientId, string $userId, array $scopes): array
    {
        if (!in_array(self::ROLES_SCOPE, $scopes, true)) {
            return [];
        }

        return ['roles' => $this->roles->effectiveFor($userId, $clientId)];
    }

    /**
     * @param string[] $scopes
     *
     * @return array<string, mixed>|null null when the person no longer exists
     */
    public function claimsFor(string $userId, array $scopes): ?array
    {
        $sub = $userId;
        $user = $this->users->find($sub);
        if ($user === null) {
            return null;
        }
        $available = [
            'name' => $user['name'],
            'email' => $user['email'],
            'email_verified' => (bool) $user['email_verified'],
            'groups' => $this->users->groupNames($sub),
        ];

        $claims = [];
        foreach ($scopes as $scope) {
            foreach ($this->scopeClaims[$scope] ?? [] as $claim) {
                $claims[$claim] = $available[$claim];
            }
        }

        return $claims;
    }
}
