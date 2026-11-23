<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\ScimClients;

use PhpSso\Store\ScimClients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlScimClients;

#[CoversMethod(ScimClients::class, 'create')]
#[CoversMethod(ScimClients::class, 'authenticate')]
#[CoversMethod(ScimClients::class, 'rotate')]
#[CoversMethod(ScimClients::class, 'delete')]
final class TokenTest extends TestCase
{
    public function testTheTokenWorksAndOnlyItsHashIsKept(): void
    {
        // Arrange
        $data = new TestData();
        $clients = new SqlScimClients($data->db);
        $created = $clients->create('Acme', ['Acme.com'], true, 'deactivate', false);

        // Act
        $client = $clients->authenticate($created['token']);

        // Assert
        $this->assertSame($created['id'], (int) $client['id']);
        $this->assertSame(['acme.com'], $client['domains']);
        $this->assertArrayNotHasKey('token_hash', $client);
        $stored = (string) $data->db->pdo->query('SELECT token_hash FROM scim_clients')->fetchColumn();
        $this->assertSame(hash('sha256', $created['token']), $stored);
        $this->assertStringNotContainsString($created['token'], $stored);
        $this->assertStringStartsWith('scim_', $created['token']);
    }

    public function testAWrongOrEmptyTokenIsNotAccepted(): void
    {
        // Arrange
        $clients = new SqlScimClients((new TestData())->db);
        $clients->create('Acme', ['acme.com'], true, 'deactivate', false);

        // Act
        $results = [$clients->authenticate('scim_wrong'), $clients->authenticate('')];

        // Assert
        $this->assertSame([null, null], $results);
    }

    public function testASwitchedOffClientsTokenStopsWorking(): void
    {
        // Arrange
        $clients = new SqlScimClients((new TestData())->db);
        $created = $clients->create('Acme', ['acme.com'], false, 'deactivate', false);

        // Act
        $client = $clients->authenticate($created['token']);

        // Assert
        $this->assertNull($client);
    }

    public function testANewTokenReplacesTheOldOne(): void
    {
        // Arrange
        $clients = new SqlScimClients((new TestData())->db);
        $created = $clients->create('Acme', ['acme.com'], true, 'deactivate', false);

        // Act
        $new = $clients->rotate($created['id']);

        // Assert
        $this->assertNull($clients->authenticate($created['token']));
        $this->assertSame($created['id'], (int) $clients->authenticate($new)['id']);
    }

    public function testDeletingAClientKeepsItsPeopleButNobodyManagesThem(): void
    {
        // Arrange
        $data = new TestData();
        $clients = new SqlScimClients($data->db);
        $created = $clients->create('Acme', ['acme.com'], true, 'deactivate', false);
        $sub = $data->user('alice');
        (new \PhpSso\Store\Sql\SqlScimUsers($data->db))->attach($created['id'], $sub, 'alice@acme.com', null, null, null);

        // Act
        $clients->delete($created['id']);

        // Assert
        $this->assertNotNull($data->users->find($sub));
        $this->assertNull((new \PhpSso\Store\Sql\SqlScimUsers($data->db))->managedBy($sub));
    }
}
