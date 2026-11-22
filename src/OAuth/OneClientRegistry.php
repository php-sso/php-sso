<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\OAuthServer\Logout\SessionRegistry;

/** For a retry: the session is known to have taken part in exactly this one app, so only this one is told, and nothing is registered. */
final readonly class OneClientRegistry implements SessionRegistry
{
    public function __construct(private string $clientId)
    {
    }

    public function register(string $sessionId, string $clientId): void
    {
    }

    public function clientsOf(string $sessionId): array
    {
        return [$this->clientId];
    }
}
