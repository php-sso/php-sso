<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\OAuthServer\Client\Client;
use PhpSso\OAuthServer\Resource\ResourcePolicy;
use PhpSso\Store\Clients;

/**
 * The resources (RFC 8707) a client may get access tokens for: the list on its own Settings tab, and, for an app that
 * registered itself, also the resources its registrar allows its apps. Matching is exact.
 */
final readonly class ClientResources implements ResourcePolicy
{
    public function __construct(private Clients $clients)
    {
    }

    public function allows(Client $client, string $resource): bool
    {
        $row = $this->clients->find($client->id());
        if ($row === null) {
            return false;
        }
        $allowed = $row['resources'];
        if (is_array($row['registration']) && is_string($row['registration']['registered_by'] ?? null)) {
            $registrar = $this->clients->find($row['registration']['registered_by']);
            if ($registrar !== null && is_array($registrar['registrar'])) {
                $allowed = [...$allowed, ...(array) ($registrar['registrar']['resources'] ?? [])];
            }
        }

        return in_array($resource, $allowed, true);
    }
}
