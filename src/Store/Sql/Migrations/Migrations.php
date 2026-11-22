<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

/** The migrations of the app, oldest first. A schema change is a new class here (the next id) and a line in {@see all()}. */
final class Migrations
{
    /** @return list<Migration> */
    public static function all(): array
    {
        return [
            new CreateBaselineSchema(),
            new CreateAuditLog(),
            new CreateLogoutRetries(),
            new CreatePasswordResets(),
            new AddSamlRequestSigning(),
        ];
    }
}
