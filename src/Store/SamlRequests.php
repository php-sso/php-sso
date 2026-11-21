<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * The sign-in requests we sent to company providers and have not had an answer to. The answer comes back as a POST from
 * another site, which a normal session cookie does not survive, so what we need to check it lives here, not in the
 * session: one row per request, used once.
 */
interface SamlRequests
{
    /** @param string $bindingHash the SHA-256 of the secret given to the browser that started this request (never the secret itself) */
    public function remember(string $requestId, int $connectionId, string $bindingHash, ?string $returnTo, ?int $now = null): void;

    /**
     * Takes the request out: it works once, and only while it is fresh.
     *
     * @return array{connection_id: int, binding_hash: string, return_to: ?string}|null
     */
    public function consume(string $requestId, ?int $now = null): ?array;
}
