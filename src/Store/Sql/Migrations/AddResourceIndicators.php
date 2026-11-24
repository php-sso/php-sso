<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;

/**
 * Resource indicators (RFC 8707): the resources a code and a refresh token were granted for, and the resources a client may
 * get tokens for (`clients.resources`). All three are JSON lists, NULL when there are none.
 */
final class AddResourceIndicators extends SqlMigration
{
    public function id(): string
    {
        return '0008';
    }

    public function description(): string
    {
        return 'Resource indicators';
    }

    public function up(Connection $db): void
    {
        $this->addColumn($db, 'oauth_codes', 'resources', 'TEXT');
        $this->addColumn($db, 'oauth_refresh_tokens', 'resources', 'TEXT');
        $this->addColumn($db, 'clients', 'resources', 'TEXT');
    }
}
