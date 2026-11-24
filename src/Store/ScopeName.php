<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** What a name may look like for a scope an administrator adds. */
final class ScopeName
{
    /** Letters, digits and . _ : - (no spaces or slashes), so a scope is safe in a token, a URL and a form. */
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/';

    /** The scope that lets a client register apps. It is built in: it is never listed or added, and a client has it through its own setting. */
    public const REGISTER = 'client:register';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
