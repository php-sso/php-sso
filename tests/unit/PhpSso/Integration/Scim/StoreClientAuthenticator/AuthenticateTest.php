<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Scim\StoreClientAuthenticator;

use PhpSso\Integration\Scim\StoreClientAuthenticator;
use PhpSso\ScimServer\OnDelete;
use PhpSso\Store\ScimClients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteScimClients;

#[CoversMethod(StoreClientAuthenticator::class, 'authenticate')]
final class AuthenticateTest extends TestCase
{
    public function testItDescribesTheClientTheTokenBelongsTo(): void
    {
        // Arrange
        $clients = new SqliteScimClients((new TestData())->db);
        $created = $clients->create('Acme Okta', ['Acme.com', 'acme.org'], true, 'delete', true, 'acme:', true);
        $authenticator = new StoreClientAuthenticator($clients);

        // Act
        $client = $authenticator->authenticate($created['token']);

        // Assert
        $this->assertNotNull($client);
        $this->assertSame([$created['id'], 'Acme Okta', ['acme.com', 'acme.org']], [$client->id, $client->name, $client->domains]);
        $this->assertSame([OnDelete::Delete, true, 'acme:', true], [$client->onDelete, $client->adoptExisting, $client->groupPrefix, $client->adoptGroups]);
    }

    public function testAnUnknownTokenBelongsToNoOne(): void
    {
        // Arrange
        $authenticator = new StoreClientAuthenticator(new SqliteScimClients((new TestData())->db));

        // Act
        $client = $authenticator->authenticate('scim_unknown');

        // Assert
        $this->assertNull($client);
    }

    public function testASwitchedOffClientIsNotAuthenticated(): void
    {
        // Arrange
        $clients = new SqliteScimClients((new TestData())->db);
        $created = $clients->create('Acme Okta', ['acme.com'], false, 'deactivate', false);
        $authenticator = new StoreClientAuthenticator($clients);

        // Act
        $client = $authenticator->authenticate($created['token']);

        // Assert
        $this->assertNull($client);
    }
}
