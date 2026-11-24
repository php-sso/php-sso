<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\OAuthServer\Client\Client;
use PhpSso\OAuthServer\Client\SupportsLogout;
use PhpSso\OAuthServer\Grant\GrantType;
use PhpSso\Store\ScopeName;

/** A client from the database, as the OAuth server sees it. */
final readonly class SsoClient implements Client, SupportsLogout
{
    /**
     * @param list<string> $redirectUris
     * @param list<string> $grants       grant type names, as stored
     * @param list<string>|null $scopes  the scopes the client may ask for; null means every scope the server offers
     * @param list<string> $postLogoutRedirectUris
     */
    public function __construct(
        private string $id,
        public string $name,
        private array $redirectUris,
        private bool $confidential,
        private array $grants,
        public ?string $secretHash,
        private ?array $scopes,
        private ?string $backchannelLogoutUri,
        private array $postLogoutRedirectUris,
        private bool $mayRegister = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row of Store\Clients, decoded
     * @param list<string>|null $scopesWhenUnlisted what a client with no list of scopes of its own may ask for (null: every scope
     *                                              the server offers). Pass the built-in scopes, so that a scope an administrator
     *                                              adds is never handed to a client that was not given it.
     */
    public static function fromRow(array $row, ?array $scopesWhenUnlisted = null): self
    {
        return new self(
            $row['client_id'], $row['name'], array_values($row['redirect_uris']), $row['confidential'], array_values($row['grants']), $row['secret_hash'],
            $row['scopes'] === null ? $scopesWhenUnlisted : array_values($row['scopes']), $row['backchannel_logout_uri'], array_values($row['post_logout_redirect_uris']),
            ($row['registrar'] ?? null) !== null,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function redirectUris(): array
    {
        return $this->redirectUris;
    }

    public function isConfidential(): bool
    {
        return $this->confidential;
    }

    public function allowsGrant(GrantType $grant): bool
    {
        return in_array($grant->value, $this->grants, true);
    }

    /**
     * A client with no scope list of its own (older ones) may ask for every scope the server offers. The scope that lets a client
     * register apps is the exception: only the client's own "may register apps" setting decides it, never a list.
     */
    public function allowsScope(string $scope): bool
    {
        if ($scope === ScopeName::REGISTER) {
            return $this->mayRegister;
        }

        return $this->scopes === null || in_array($scope, $this->scopes, true);
    }

    public function backchannelLogoutUri(): ?string
    {
        return $this->backchannelLogoutUri;
    }

    public function postLogoutRedirectUris(): array
    {
        return $this->postLogoutRedirectUris;
    }
}
