<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\Contract\ClientAuthenticator;
use PhpSso\ScimServer\OnDelete;
use PhpSso\ScimServer\ProvisioningClient;
use PhpSso\Store\ScimClients;

/** Finds the provisioning client a bearer token belongs to in the `scim_clients` table. */
final class StoreClientAuthenticator implements ClientAuthenticator
{
    public function __construct(private ScimClients $clients)
    {
    }

    public function authenticate(string $token): ?ProvisioningClient
    {
        $row = $this->clients->authenticate($token);
        if ($row === null) {
            return null;
        }

        return new ProvisioningClient(
            (int) $row['id'],
            (string) $row['name'],
            $row['domains'],
            OnDelete::tryFrom((string) ($row['on_delete'] ?? '')) ?? OnDelete::Deactivate,
            (bool) $row['adopt_existing'],
            (string) ($row['group_prefix'] ?? ''),
            (bool) ($row['adopt_groups'] ?? false),
        );
    }
}
