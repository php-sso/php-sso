<?php

declare(strict_types=1);

namespace PhpSso\Startup;

/** What must be true of the setup before the server serves anyone, so that a mistake stops it instead of weakening it quietly. */
final class Checks
{
    /**
     * @param string $issuer the public address (SSO_PUBLIC_URL)
     * @param bool $demo whether the demo data (people with well-known passwords) is switched on
     * @param bool $allowInsecureHttp SSO_ALLOW_INSECURE_HTTP: the operator knows tokens and passwords will cross the network unencrypted
     * @return list<string> what is wrong, in words for the person who runs the server
     */
    public static function problems(string $issuer, bool $demo, bool $allowInsecureHttp): array
    {
        $parts = parse_url($issuer);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return ["SSO_PUBLIC_URL ({$issuer}) must be an address like https://sso.example.com."];
        }

        $local = self::isLocal($host);
        $problems = [];
        if ($scheme === 'http' && !$local && !$allowInsecureHttp) {
            $problems[] = "SSO_PUBLIC_URL is {$issuer}, which is not HTTPS: passwords and tokens would cross the network unencrypted. Use an https:// address "
                . '(a reverse proxy may do the TLS), or set SSO_ALLOW_INSECURE_HTTP=1 if you accept that.';
        }
        if ($demo && !$local) {
            $problems[] = "SSO_DEMO_DATA is on, which creates people with well-known passwords, but SSO_PUBLIC_URL ({$issuer}) is not a local address. Turn SSO_DEMO_DATA off.";
        }

        return $problems;
    }

    private static function isLocal(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || preg_match('/^127\.\d+\.\d+\.\d+$/', $host) === 1;
    }
}
