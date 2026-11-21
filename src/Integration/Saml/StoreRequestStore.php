<?php

declare(strict_types=1);

namespace PhpSso\Integration\Saml;

use PhpSso\Saml\ServiceProvider\Contract\RequestStore;
use PhpSso\Saml\ServiceProvider\PendingRequest;
use PhpSso\Store\SamlRequests;

/** The sign-in requests waiting for an answer, kept in the `saml_requests` table (used once, for ten minutes). */
final class StoreRequestStore implements RequestStore
{
    public function __construct(private SamlRequests $requests)
    {
    }

    public function remember(string $requestId, int $connectionId, string $bindingHash, ?string $returnTo): void
    {
        $this->requests->remember($requestId, $connectionId, $bindingHash, $returnTo);
    }

    public function consume(string $requestId): ?PendingRequest
    {
        $row = $this->requests->consume($requestId);

        return $row === null ? null : new PendingRequest($row['connection_id'], $row['binding_hash'], $row['return_to']);
    }
}
