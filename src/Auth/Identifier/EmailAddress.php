<?php

declare(strict_types=1);

namespace PhpSso\Auth\Identifier;

/** Shared rules for identifiers that are email addresses. */
final class EmailAddress
{
    public static function normalize(string $input): string
    {
        return mb_strtolower(trim($input));
    }

    /** The part after the last `@`, or null when there is no `@` or nothing on one of its sides. */
    public static function domain(string $input): ?string
    {
        $email = self::normalize($input);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return null;
        }

        return substr($email, $at + 1);
    }
}
