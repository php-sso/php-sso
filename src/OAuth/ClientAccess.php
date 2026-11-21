<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Store\Clients;
use PhpSso\OAuthServer\Authorization\AccessPolicy;
use PhpSso\OAuthServer\Client\Client;

/** Whether a person may use a client: open to everyone, or limited to the groups and people linked to it. */
final class ClientAccess implements AccessPolicy
{
    public function __construct(private Clients $clients)
    {
    }

    public function mayUse(Client $client, string $userId): bool
    {
        return $this->clients->userMayUse($client->id(), $userId);
    }
}
