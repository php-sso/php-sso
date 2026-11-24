<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\OAuthServer\Registration\RegistrarPolicy;
use PhpSso\Store\Clients;
use PhpSso\Store\Scopes;

/**
 * Which clients may register apps (RFC 7591), taken from each client's own setting in the admin panel, and what the apps
 * a registrar registers may get. An app that registered itself is never a registrar.
 */
final readonly class ClientRegistrars implements RegistrarPolicy
{
    public function __construct(private Clients $clients, private Scopes $scopes)
    {
    }

    public function mayRegister(string $registrarClientId): bool
    {
        $row = $this->clients->find($registrarClientId);

        return $row !== null && is_array($row['registrar']) && $row['registration'] === null;
    }

    public function allowedScopes(string $registrarClientId): ?array
    {
        $row = $this->clients->find($registrarClientId);
        if ($row === null || !is_array($row['registrar'])) {
            return $this->scopes->builtinNames();
        }

        // Only scopes that still exist: one an administrator deleted since is not handed out.
        return array_values(array_intersect($this->scopes->names(), (array) ($row['registrar']['scopes'] ?? [])));
    }

    public function anyRegistrar(): bool
    {
        return $this->clients->anyRegistrar();
    }
}
