<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * OAuth clients. Rows are returned with `redirect_uris` and `grants` as arrays
 * and `confidential` as bool.
 */
interface Clients
{
    public function all(): array;

    /**
     * The clients that may ask for this scope. A client with no list of its own (an old record) may ask for the built-in
     * scopes only, so it never counts for one an administrator added.
     *
     * @return list<array{client_id: string, name: string}>
     */
    public function usingScope(string $scope): array;

    /** Clients whose name or ID contains the text; for the admin search. */
    public function search(string $q, int $limit): array;

    public function find(string $clientId): ?array;

    public function count(): int;

    /** @param array{client_id: string, name: string, secret_hash: ?string, confidential: bool, redirect_uris: string[], grants: string[], backchannel_logout_uri?: ?string, post_logout_redirect_uris?: string[], scopes?: ?string[], restricted?: bool} $c */
    public function insert(array $c): void;

    /** @param string[] $redirectUris @param string[] $grants @param string[] $postLogoutUris @param string[] $scopes */
    public function update(string $clientId, string $name, array $redirectUris, array $grants, ?string $backchannelLogoutUri, array $postLogoutUris, array $scopes): void;

    /**
     * Changes a client's ID and type. Everything that points at the ID moves with it. The ID is not cascaded
     * by the schema, so the foreign key checks wait until the whole change is in place.
     * Tokens already issued name the old ID: access tokens run out on their own, refresh tokens stop working.
     *
     * @param ?string $secretHash the secret hash to store afterwards; null for a public client
     */
    public function change(string $clientId, string $newClientId, bool $confidential, ?string $secretHash): void;

    public function updateSecretHash(string $clientId, string $hash): void;

    public function delete(string $clientId): void;

    public function setRestricted(string $clientId, bool $restricted): void;

    /**
     * Open clients accept every user. Restricted clients accept the people linked to them directly and
     * the members of the groups linked to them; with nobody linked, nobody gets in.
     */
    public function userMayUse(string $clientId, string $userSub): bool;

    /**
     * Why a person may or may not use a client, for the access tester.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function explainAccess(string $clientId, string $userSub): array;

    /** Who each restricted client is limited to, for the list page: client id => group names and number of people. */
    public function accessSummary(): array;
}
