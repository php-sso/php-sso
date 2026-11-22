<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\StoreConnectionRepository;

use PhpSso\Integration\Saml\StoreConnectionRepository;
use PhpSso\Store\SamlConnections;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlSamlConnections;

#[CoversMethod(StoreConnectionRepository::class, 'find')]
final class FindTest extends TestCase
{
    public function testItDescribesTheProviderTheAdministratorSetUp(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);
        $id = $connections->create(['name' => 'Acme', 'idp_entity_id' => 'https://idp.acme.test/e', 'idp_sso_url' => 'https://idp.acme.test/sso', 'idp_certificate' => 'CERT', 'enabled' => true, 'create_users' => false, 'link_existing' => false], ['Acme.com', 'acme.org']);
        $repository = new StoreConnectionRepository($connections);

        // Act
        $connection = $repository->find($id);

        // Assert
        $this->assertNotNull($connection);
        $this->assertSame([$id, true, 'https://idp.acme.test/e', 'https://idp.acme.test/sso', 'CERT'], [$connection->id, $connection->enabled, $connection->entityId, $connection->ssoUrl, $connection->certificate]);
        $this->assertEqualsCanonicalizing(['acme.com', 'acme.org'], $connection->domains);
    }

    public function testAConnectionSetToSignRequestsSaysSo(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);
        $id = $connections->create(['name' => 'Acme', 'idp_entity_id' => 'e', 'idp_sso_url' => 'https://x.test', 'idp_certificate' => 'x', 'enabled' => true, 'create_users' => false, 'link_existing' => false, 'sign_requests' => true], ['acme.com']);

        // Act
        $connection = (new StoreConnectionRepository($connections))->find($id);

        // Assert
        $this->assertTrue($connection?->signRequests);
    }

    public function testASwitchedOffConnectionSaysSo(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);
        $id = $connections->create(['name' => 'Acme', 'idp_entity_id' => 'e', 'idp_sso_url' => 'https://x.test', 'idp_certificate' => 'x', 'enabled' => false, 'create_users' => false, 'link_existing' => false], ['acme.com']);

        // Act
        $connection = (new StoreConnectionRepository($connections))->find($id);

        // Assert
        $this->assertNotNull($connection);
        $this->assertFalse($connection->enabled);
    }

    public function testAConnectionThatDoesNotExistIsNull(): void
    {
        // Arrange
        $repository = new StoreConnectionRepository(new SqlSamlConnections((new TestData())->db));

        // Act
        $connection = $repository->find(999);

        // Assert
        $this->assertNull($connection);
    }
}
