<?php

declare(strict_types=1);

namespace PhpSso\Net;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The address of the person making a request. X-Forwarded-For is only believed when the direct peer
 * (REMOTE_ADDR) is a trusted proxy; the chain is read from the right, skipping trusted proxies, so
 * addresses a client invents on the left are never used.
 */
final class ClientIp
{
    /** @param string[] $trustedProxies addresses or CIDR ranges of reverse proxies and load balancers */
    public function __construct(private array $trustedProxies = [])
    {
    }

    public function of(ServerRequestInterface $request): string
    {
        $peer = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if (!$this->trusted($peer)) {
            return $peer;
        }

        $chain = [];
        foreach ($request->getHeader('X-Forwarded-For') as $line) {
            foreach (explode(',', $line) as $hop) {
                $chain[] = trim($hop);
            }
        }
        foreach (array_reverse($chain) as $hop) {
            if (Cidr::normalize($hop) === null || str_contains($hop, '/')) {
                return $peer; // malformed header: don't guess
            }
            if (!$this->trusted($hop)) {
                return $hop;
            }
        }

        return $peer;
    }

    private function trusted(string $ip): bool
    {
        foreach ($this->trustedProxies as $range) {
            if (Cidr::contains($range, $ip)) {
                return true;
            }
        }

        return false;
    }
}
