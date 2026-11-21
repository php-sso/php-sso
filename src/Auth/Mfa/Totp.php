<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

/** Time-based one-time passwords (RFC 6238 with HMAC-SHA1, 6 digits, 30 seconds): what authenticator apps compute. */
final class Totp
{
    public const PERIOD = 30;

    public static function generateSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), Base32::decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a typed code against the current 30 second step and the ones either side (clocks drift). A code only
     * counts for a step after `$afterCounter`, so one that was already accepted cannot be used again.
     *
     * @return int|null the step the code belongs to, or null when it is wrong or already used
     */
    public static function verify(string $secret, string $input, int $afterCounter = 0, ?int $now = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $input) !== 1) {
            return null;
        }

        $current = intdiv($now ?? time(), self::PERIOD);
        $match = null;
        foreach ([$current - 1, $current, $current + 1] as $counter) {
            if (hash_equals(self::code($secret, $counter), $input) && $counter > $afterCounter) {
                $match = $counter;
            }
        }

        return $match;
    }

    /** The `otpauth://` address an authenticator app reads from a QR code or opens as a link. */
    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
