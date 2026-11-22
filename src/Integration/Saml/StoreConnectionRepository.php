<?php

declare(strict_types=1);

namespace PhpSso\Integration\Saml;

use PhpSso\Saml\ServiceProvider\Contract\ConnectionRepository;
use PhpSso\Saml\ServiceProvider\IdpConnection;
use PhpSso\Store\SamlConnections;

/** The company identity providers an administrator set up, read from the `saml_connections` table. */
final class StoreConnectionRepository implements ConnectionRepository
{
    public function __construct(private SamlConnections $connections)
    {
    }

    public function find(int $id): ?IdpConnection
    {
        $row = $this->connections->find($id);
        if ($row === null) {
            return null;
        }

        return new IdpConnection(
            (int) $row['id'],
            (bool) $row['enabled'],
            (string) $row['idp_entity_id'],
            (string) $row['idp_sso_url'],
            (string) $row['idp_certificate'],
            $row['domains'],
            (bool) ($row['sign_requests'] ?? false),
        );
    }
}
