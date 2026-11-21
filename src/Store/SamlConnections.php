<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Company identity providers people can sign in through with SAML. A connection also claims email domains: a person
 * who types an address at one of them is sent straight to that company's provider, and only that provider may vouch
 * for such addresses.
 */
interface SamlConnections
{
    /** @return list<array<string, mixed>> every connection with its `domains` (a list) */
    public function all(): array;

    public function find(int $id): ?array;

    /** The switched-on connection that claims this email domain, or null. */
    public function findByDomain(string $domain): ?array;

    /** The connection that claims this email domain (switched on or not), as an id, or null. */
    public function domainOwner(string $domain): ?int;

    /**
     * Whether this person's address is at a domain a switched-on connection claims: their company signs them in, so a
     * password here would bypass its two-step verification and its offboarding.
     */
    public function managesAddress(string $email): bool;

    /**
     * @param array{name: string, idp_entity_id: string, idp_sso_url: string, idp_certificate: string, enabled: bool, create_users: bool, link_existing: bool} $data
     * @param list<string> $domains
     */
    public function create(array $data, array $domains): int;

    /** @param array{name: string, idp_entity_id: string, idp_sso_url: string, idp_certificate: string, enabled: bool, create_users: bool, link_existing: bool} $data @param list<string> $domains */
    public function update(int $id, array $data, array $domains): void;

    /** Deletes it. People who signed in through it keep their accounts but lose that way in. */
    public function delete(int $id): void;

    /** @return list<string> */
    public function domainsOf(int $id): array;

    /** How many people signed in through (are connected to) this connection. */
    public function peopleCount(int $id): int;
}
