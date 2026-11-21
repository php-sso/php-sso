<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Store\Sessions;
use PhpSso\OAuthServer\Logout\SessionRegistry;
use PhpSso\OAuthServer\Logout\SessionTerminator;

/** What the OAuth server needs to know about our login sessions: who took part in them, and how to end one. */
final readonly class SessionLinks implements SessionRegistry, SessionTerminator
{
    public function __construct(private Sessions $sessions)
    {
    }

    public function register(string $sessionId, string $clientId): void
    {
        $this->sessions->addClient($sessionId, $clientId);
    }

    public function clientsOf(string $sessionId): array
    {
        return array_values($this->sessions->clientIds($sessionId));
    }

    /** The OAuth server revokes the tokens and notifies the apps itself, so this only marks the session ended. */
    public function terminate(string $sessionId): void
    {
        $this->sessions->markEnded($sessionId);
    }
}
