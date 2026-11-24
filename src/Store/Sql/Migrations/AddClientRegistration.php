<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;

/** Marks the clients that registered themselves (RFC 7591): `clients.registration` holds what only they have (JSON), and is NULL for the others. */
final class AddClientRegistration extends SqlMigration
{
    public function id(): string
    {
        return '0006';
    }

    public function description(): string
    {
        return 'Dynamically registered clients';
    }

    public function up(Connection $db): void
    {
        $this->addColumn($db, 'clients', 'registration', 'TEXT');
    }
}
