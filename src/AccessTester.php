<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Net\Cidr;
use PhpSso\Store\Clients;
use PhpSso\Store\Users;

/**
 * "If I was this address, could this person sign in to this client?" Runs the same checks the server runs, in the
 * order it runs them, and reports each one. Every check is a step, so a new kind of rule (for example an
 * authorization server) is added by appending a step to evaluate().
 */
final class AccessTester
{
    public function __construct(private IpPolicy $ipPolicy, private Clients $clients, private Users $users)
    {
    }

    /**
     * @return array{allowed: bool, steps: list<array{title: string, status: string, detail: string}>}
     *         status is 'pass', 'fail' or 'skipped'; skipped steps do not count against the result
     */
    public function evaluate(string $ip, ?string $clientId, ?string $userSub): array
    {
        $steps = [];

        $client = $clientId === null ? null : $this->clients->find($clientId);
        $user = $userSub === null ? null : $this->users->find($userSub);

        $steps[] = $client !== null && $this->ipPolicy->ignoresGeneral($client['client_id'])
            ? $this->skipped('General IP rules', "{$client['name']} ignores the general rules.")
            : $this->step('General IP rules', $this->ipPolicy->explain($ip));

        $steps[] = $client === null
            ? $this->skipped("IP rules of the client", 'No client chosen.')
            : $this->step("IP rules of {$client['name']}", $this->ipPolicy->explain($ip, $client['client_id']));

        if ($client === null || $user === null) {
            $steps[] = $this->skipped('Who may use the client', $client === null ? 'No client chosen.' : 'No person chosen.');
        } else {
            $steps[] = $this->step("Who may use {$client['name']}", $this->clients->explainAccess($client['client_id'], $user['sub']));
        }

        $failed = array_filter($steps, static fn (array $s): bool => $s['status'] === 'fail');

        return ['allowed' => $failed === [], 'steps' => $steps];
    }

    /** Whether the text is a single IP address (not a range). */
    public static function validIp(string $ip): bool
    {
        return Cidr::normalize($ip) !== null && !str_contains($ip, '/');
    }

    /** @param array{allowed: bool, reason: string} $result */
    private function step(string $title, array $result): array
    {
        return ['title' => $title, 'status' => $result['allowed'] ? 'pass' : 'fail', 'detail' => $result['reason']];
    }

    private function skipped(string $title, string $detail): array
    {
        return ['title' => $title, 'status' => 'skipped', 'detail' => $detail];
    }
}
