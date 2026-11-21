<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Store\Clients;
use PhpSso\OAuthServer\Client\Client;
use PhpSso\OAuthServer\Client\ClientRepository;
use PhpSso\OAuthServer\Client\EqualisesVerificationTime;

/** Looks clients up in the database for the OAuth server. */
final class RegisteredClients implements ClientRepository, EqualisesVerificationTime
{
    private static ?string $dummyHash = null;

    /** @param list<string>|null $scopesWhenUnlisted what a client with no list of scopes of its own may ask for: see SsoClient::fromRow() */
    public function __construct(private Clients $clients, private ?array $scopesWhenUnlisted = null)
    {
    }

    public function find(string $clientId): ?Client
    {
        $row = $this->clients->find($clientId);

        return $row === null ? null : SsoClient::fromRow($row, $this->scopesWhenUnlisted);
    }

    public function verifySecret(Client $client, string $secret): bool
    {
        return $client instanceof SsoClient && $client->secretHash !== null && password_verify($secret, $client->secretHash);
    }

    /** A client id that does not exist costs one password check too, like a real client with a wrong secret. */
    public function spendVerificationTime(string $secret): void
    {
        self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        password_verify($secret, self::$dummyHash);
    }
}
