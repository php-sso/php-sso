<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\UniquenessViolation;

/** Turns the database's "that value is taken" error into the one the SCIM server understands. */
trait TranslatesConstraintErrors
{
    private static function translate(\PDOException $e): \Throwable
    {
        return (string) $e->getCode() === '23000' ? new UniquenessViolation('A unique value is already taken.', 0, $e) : $e;
    }
}
