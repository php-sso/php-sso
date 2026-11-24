<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use LogicException;
use PhpSso\OAuthServer\Grant\GrantType;
use PhpSso\OAuthServer\Registration\ClientMetadata;
use PhpSso\OAuthServer\Registration\ClientRegistration;
use PhpSso\OAuthServer\Registration\ClientRegistrations;
use PhpSso\Store\Clients;

/**
 * Keeps the clients that registered themselves (RFC 7591) in the `clients` table, next to the ones an administrator
 * made, so they show up in the admin panel and can be restricted, edited or deleted there like any other.
 *
 * The columns that the rest of the app reads (name, redirect URIs, grants, scopes, logout URIs) are the truth for those
 * fields; `registration` holds the rest: the hash of the registration access token, who registered the client, and the
 * descriptive metadata. This class never touches a client an administrator made.
 */
final readonly class StoredRegistrations implements ClientRegistrations
{
    public function __construct(private Clients $clients)
    {
    }

    public function find(string $clientId): ?ClientRegistration
    {
        $row = $this->clients->find($clientId);
        if ($row === null || !is_array($row['registration'])) {
            return null;
        }
        $extra = $row['registration'];
        $metadata = (array) ($extra['metadata'] ?? []);
        $name = (string) $row['name'];

        return new ClientRegistration(
            $row['client_id'],
            new ClientMetadata(
                redirectUris: array_values($row['redirect_uris']),
                responseTypes: array_values((array) ($metadata['response_types'] ?? [])),
                grantTypes: array_values(array_map(GrantType::from(...), $row['grants'])),
                tokenEndpointAuthMethod: (string) ($metadata['token_endpoint_auth_method'] ?? ($row['confidential'] ? 'client_secret_basic' : 'none')),
                scopes: array_values($row['scopes'] ?? []),
                clientName: ($extra['named'] ?? true) || $name !== self::fallbackName($row['client_id']) ? $name : null,
                clientUri: $metadata['client_uri'] ?? null,
                logoUri: $metadata['logo_uri'] ?? null,
                tosUri: $metadata['tos_uri'] ?? null,
                policyUri: $metadata['policy_uri'] ?? null,
                contacts: array_values((array) ($metadata['contacts'] ?? [])),
                softwareId: $metadata['software_id'] ?? null,
                softwareVersion: $metadata['software_version'] ?? null,
                backchannelLogoutUri: $row['backchannel_logout_uri'],
                postLogoutRedirectUris: array_values($row['post_logout_redirect_uris']),
            ),
            $row['secret_hash'],
            (string) ($extra['token_hash'] ?? ''),
            (int) ($extra['issued_at'] ?? 0),
            $extra['registered_by'] ?? null,
        );
    }

    public function save(ClientRegistration $registration): void
    {
        $metadata = $registration->metadata;
        $this->clients->saveRegistered([
            'client_id' => $registration->clientId,
            'name' => $metadata->clientName ?? self::fallbackName($registration->clientId),
            'secret_hash' => $registration->secretHash,
            'confidential' => $metadata->isConfidential(),
            'redirect_uris' => $metadata->redirectUris,
            'grants' => array_map(static fn (GrantType $g): string => $g->value, $metadata->grantTypes),
            'backchannel_logout_uri' => $metadata->backchannelLogoutUri,
            'post_logout_redirect_uris' => $metadata->postLogoutRedirectUris,
            'scopes' => $metadata->scopes,
            'registration' => [
                'token_hash' => $registration->registrationTokenHash,
                'registered_by' => $registration->registeredBy,
                'issued_at' => $registration->issuedAt,
                'named' => $metadata->clientName !== null,
                'metadata' => $metadata->toArray(),
            ],
        ]);
    }

    public function delete(string $clientId): void
    {
        if ($this->find($clientId) === null) {
            throw new LogicException("Client {$clientId} was not registered through the registration endpoint.");
        }
        $this->clients->delete($clientId);
    }

    private static function fallbackName(string $clientId): string
    {
        return 'Registered client ' . substr($clientId, 0, 8);
    }
}
