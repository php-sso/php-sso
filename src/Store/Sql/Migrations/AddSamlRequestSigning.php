<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;

/** Whether the sign-in requests to a company's identity provider are signed (`saml_connections.sign_requests`). */
final class AddSamlRequestSigning extends SqlMigration
{
    public function id(): string
    {
        return '0005';
    }

    public function description(): string
    {
        return 'Signed SAML requests';
    }

    public function up(Connection $db): void
    {
        $this->addColumn($db, 'saml_connections', 'sign_requests', 'BIGINT NOT NULL DEFAULT 0');
    }
}
