<?php

declare(strict_types=1);

namespace PhpSso\Net;

/** IPv4 and IPv6 addresses and CIDR ranges ("10.0.0.0/8", "2001:db8::/32", or a single address). */
final class Cidr
{
    /** Normalizes an address or range to its canonical text form, or returns null when it is not valid. */
    public static function normalize(string $value): ?string
    {
        $parsed = self::parse(trim($value));
        if ($parsed === null) {
            return null;
        }
        [$bin, $bits] = $parsed;

        return inet_ntop($bin) . ($bits === strlen($bin) * 8 ? '' : '/' . $bits);
    }

    /** Whether the address lies inside the range (an address with no prefix matches only itself). */
    public static function contains(string $range, string $ip): bool
    {
        $r = self::parse($range);
        $a = self::parse($ip);
        if ($r === null || $a === null || strlen($r[0]) !== strlen($a[0]) || $a[1] !== strlen($a[0]) * 8) {
            return false;
        }

        return self::prefix($r[0], $r[1]) === self::prefix($a[0], $r[1]);
    }

    /** @return array{string, int}|null packed address and prefix length */
    private static function parse(string $value): ?array
    {
        $addr = $value;
        $bits = null;
        if (str_contains($value, '/')) {
            [$addr, $len] = explode('/', $value, 2);
            if ($len === '' || !ctype_digit($len)) {
                return null;
            }
            $bits = (int) $len;
        }
        $bin = filter_var($addr, FILTER_VALIDATE_IP) !== false ? inet_pton($addr) : false;
        if ($bin === false) {
            return null;
        }
        $bin = self::unmap($bin);
        $max = strlen($bin) * 8;
        if ($bits !== null && strlen($addr) && str_contains($addr, ':') && $max === 32) {
            $bits -= 96; // "::ffff:1.2.3.4/120" is the IPv4 range /24
        }
        $bits ??= $max;
        if ($bits < 0 || $bits > $max) {
            return null;
        }

        return [$bin, $bits];
    }

    /** IPv4-mapped IPv6 addresses (::ffff:1.2.3.4) are treated as the IPv4 address. */
    private static function unmap(string $bin): string
    {
        return strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff") ? substr($bin, 12) : $bin;
    }

    /** The first $bits bits of the address, with the rest zeroed. */
    private static function prefix(string $bin, int $bits): string
    {
        $out = '';
        foreach (str_split($bin) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($byte) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return $out;
    }
}
