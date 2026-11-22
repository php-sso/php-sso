<?php

declare(strict_types=1);

namespace PhpSso\Startup;

use PhpSso\Store\SchemaTooNew;

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

    /**
     * What is wrong when migrations are pending and the server was told not to run them (SSO_AUTO_MIGRATE off).
     *
     * @param list<string> $pending the ids of the migrations that have not run
     * @return list<string>
     */
    public static function pendingMigrations(array $pending): array
    {
        if ($pending === []) {
            return [];
        }

        return ['The database schema is not up to date (pending migrations: ' . implode(', ', $pending) . '). Run bin/migrate up, or set SSO_AUTO_MIGRATE=1 to let the server do it when it starts.'];
    }

    /**
     * @param list<string> $unknown the schema versions the database has that this version does not know
     * @return list<string>
     */
    public static function unknownMigrations(array $unknown): array
    {
        return $unknown === [] ? [] : [SchemaTooNew::describe($unknown)];
    }

    /**
     * What is wrong with the email settings. Nothing is checked while no mail server is set (the password reset is simply off).
     *
     * @param array<string, mixed> $mail the `mail` part of the config
     * @return list<string>
     */
    public static function mailProblems(array $mail): array
    {
        $host = strtolower((string) ($mail['host'] ?? ''));
        if ($host === '') {
            return [];
        }

        $problems = [];
        if (filter_var($mail['from'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            $problems[] = 'SSO_SMTP_HOST is set, so SSO_MAIL_FROM must be the address the mail is sent from, such as sso@example.com.';
        }
        if (!in_array($mail['encryption'] ?? '', ['starttls', 'tls', 'none'], true)) {
            $problems[] = 'SSO_SMTP_ENCRYPTION must be starttls, tls or none.';
        } elseif ($mail['encryption'] === 'none' && !self::isTrustedMailHost($host)) {
            $problems[] = "SSO_SMTP_ENCRYPTION is none and the mail server ({$host}) is not on this machine or a private network: password reset links would cross the network unencrypted. Use starttls or tls.";
        }
        if (!is_int($mail['port'] ?? null) || $mail['port'] < 1 || $mail['port'] > 65535) {
            $problems[] = 'SSO_SMTP_PORT must be a port number.';
        }
        if (($mail['ca_file'] ?? '') !== '' && !is_readable((string) $mail['ca_file'])) {
            $problems[] = 'SSO_SMTP_CA_FILE (' . $mail['ca_file'] . ') cannot be read.';
        }

        return $problems;
    }

    /** A mail server that plain SMTP may be used with: this machine, a private or reserved IP address, or a name without a dot (a container or a host of a private network). */
    private static function isTrustedMailHost(string $host): bool
    {
        if (self::isLocal($host) || !str_contains($host, '.')) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function isLocal(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1' || preg_match('/^127\.\d+\.\d+\.\d+$/', $host) === 1;
    }
}
