<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\Net\Cidr;
use PhpSso\Store\IpRules;

/**
 * Which IP addresses may sign in, overall or to one client (a client can opt out of the overall rules). A deny rule always wins. When any allow rule exists, only addresses
 * matching one of them are let in. With no rules at all, everyone is allowed.
 */
final class IpPolicy
{
    public function __construct(private IpRules $rules)
    {
    }

    /** Whether the address may sign in at all (clientId null), or sign in to that client. */
    public function allows(string $ip, ?string $clientId = null): bool
    {
        return $this->explain($ip, $clientId)['allowed'];
    }

    /**
     * Whether the address may sign in to the client: the general rules and the client's own rules both have to allow it,
     * unless the client ignores the general rules.
     */
    public function allowsClient(string $ip, string $clientId): bool
    {
        return ($this->ignoresGeneral($clientId) || $this->allows($ip)) && $this->allows($ip, $clientId);
    }

    public function ignoresGeneral(string $clientId): bool
    {
        return $this->rules->ignoresGeneral($clientId);
    }

    /** @return array{allowed: bool, reason: string} the decision, and the rule or lack of rules behind it */
    public function explain(string $ip, ?string $clientId = null): array
    {
        return $this->decide($this->rules->ranges('allow', $clientId), $this->rules->ranges('deny', $clientId), $ip);
    }

    /** Whether the address would still be allowed after adding a rule of this type, used to warn about lock-outs. */
    public function allowsAfterAdding(string $type, string $cidr, string $ip, ?string $clientId = null): bool
    {
        $allow = $this->rules->ranges('allow', $clientId);
        $deny = $this->rules->ranges('deny', $clientId);
        ${$type === 'allow' ? 'allow' : 'deny'}[] = $cidr;

        return $this->decide($allow, $deny, $ip)['allowed'];
    }

    /** @param string[] $allow @param string[] $deny */
    private function decide(array $allow, array $deny, string $ip): array
    {
        foreach ($deny as $range) {
            if (Cidr::contains($range, $ip)) {
                return ['allowed' => false, 'reason' => "Matches the deny rule {$range}."];
            }
        }
        if ($allow === []) {
            return ['allowed' => true, 'reason' => $deny === [] ? 'There are no rules.' : 'No deny rule matches, and there are no allow rules.'];
        }
        foreach ($allow as $range) {
            if (Cidr::contains($range, $ip)) {
                return ['allowed' => true, 'reason' => "Matches the allow rule {$range}."];
            }
        }

        return ['allowed' => false, 'reason' => 'Allow rules exist and none of them match.'];
    }
}
