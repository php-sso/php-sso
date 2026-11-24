<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;

/** Which clients may register apps (RFC 7591): `clients.registrar` is JSON with the scopes their apps may get, and NULL for a client that may not. */
final class AddClientRegistrar extends SqlMigration
{
    public function id(): string
    {
        return '0007';
    }

    public function description(): string
    {
        return 'Clients that may register apps';
    }

    public function up(Connection $db): void
    {
        $this->addColumn($db, 'clients', 'registrar', 'TEXT');
    }
}
