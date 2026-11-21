<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

use InvalidArgumentException;

/** Base32 (RFC 4648, no padding): how authenticator apps expect a shared secret. */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** Ignores case, spaces, dashes and `=` padding. @throws InvalidArgumentException on any other character */
    public static function decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new InvalidArgumentException('Not a base32 character: ' . $char);
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
