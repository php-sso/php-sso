<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use PhpSso\Store\ClientAuthFailures;

/**
 * Slows down guessing of client secrets. A failure counts against the client and the address together, and against
 * the address alone, so an attacker is stopped without being able to lock a real app out by naming its client id.
 */
final class ClientAuthThrottle
{
    /** @param array{enabled?: bool, per_client_ip?: int, per_ip?: int, window_seconds?: int} $limits */
    public function __construct(private ClientAuthFailures $failures, private array $limits = [])
    {
    }

    /** Seconds until this address may try again, or null when nothing stops it. */
    public function lockedFor(?string $clientId, string $ip, ?int $now = null): ?int
    {
        $limits = $this->resolved();
        if (!$limits['enabled']) {
            return null;
        }
        $now ??= time();
        $since = $now - $limits['window_seconds'];

        $waits = [];
        $perIp = $this->failures->times($ip, null, $since);
        if (count($perIp) >= $limits['per_ip']) {
            $waits[] = $perIp[count($perIp) - $limits['per_ip']] + $limits['window_seconds'] - $now;
        }
        if ($clientId !== null) {
            $perClient = $this->failures->times($ip, $clientId, $since);
            if (count($perClient) >= $limits['per_client_ip']) {
                $waits[] = $perClient[count($perClient) - $limits['per_client_ip']] + $limits['window_seconds'] - $now;
            }
        }

        return $waits === [] ? null : max(1, ...$waits);
    }

    public function recordFailure(?string $clientId, string $ip, ?int $now = null): void
    {
        $limits = $this->resolved();
        if (!$limits['enabled']) {
            return;
        }
        $now ??= time();
        $this->failures->record($clientId, $ip, $now, $now - $limits['window_seconds']);
    }

    /** @return array{enabled: bool, per_client_ip: int, per_ip: int, window_seconds: int} */
    private function resolved(): array
    {
        return [
            'enabled' => (bool) ($this->limits['enabled'] ?? true),
            'per_client_ip' => max(1, (int) ($this->limits['per_client_ip'] ?? 10)),
            'per_ip' => max(1, (int) ($this->limits['per_ip'] ?? 30)),
            'window_seconds' => max(60, (int) ($this->limits['window_seconds'] ?? 900)),
        ];
    }

}
