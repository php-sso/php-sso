<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\SamlConnections;

use PhpSso\Store\SamlConnections;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteSamlConnections;

#[CoversMethod(SamlConnections::class, 'findByDomain')]
#[CoversMethod(SamlConnections::class, 'managesAddress')]
#[CoversMethod(SamlConnections::class, 'domainOwner')]
#[CoversMethod(SamlConnections::class, 'create')]
final class DomainsTest extends TestCase
{
    private function add(SamlConnections $connections, string $name, array $domains, bool $enabled = true): int
    {
        return $connections->create(['name' => $name, 'idp_entity_id' => 'https://' . $name . '.test', 'idp_sso_url' => 'https://' . $name . '.test/sso', 'idp_certificate' => 'x', 'enabled' => $enabled, 'create_users' => false, 'link_existing' => false], $domains);
    }

    public function testADomainFindsItsConnectionIgnoringCase(): void
    {
        // Arrange
        $connections = new SqliteSamlConnections((new TestData())->db);
        $id = $this->add($connections, 'acme', ['Acme.com']);

        // Act
        $found = $connections->findByDomain('ACME.COM');

        // Assert
        $this->assertSame($id, (int) $found['id']);
        $this->assertSame(['acme.com'], $found['domains']);
    }

    public function testASwitchedOffConnectionIsNotFoundByDomain(): void
    {
        // Arrange
        $connections = new SqliteSamlConnections((new TestData())->db);
        $this->add($connections, 'acme', ['acme.com'], false);

        // Act
        $found = $connections->findByDomain('acme.com');

        // Assert
        $this->assertNull($found);
    }

    public function testASubdomainIsNotTheClaimedDomain(): void
    {
        // Arrange
        $connections = new SqliteSamlConnections((new TestData())->db);
        $this->add($connections, 'acme', ['acme.com']);

        // Act
        $found = $connections->findByDomain('evil.acme.com.attacker.org');

        // Assert
        $this->assertNull($found);
    }

    public function testAnAddressAtAClaimedDomainIsManagedByTheCompany(): void
    {
        // Arrange
        $connections = new SqliteSamlConnections((new TestData())->db);
        $this->add($connections, 'acme', ['acme.com']);

        // Act
        $results = [$connections->managesAddress('alice@acme.com'), $connections->managesAddress('Alice@ACME.com'), $connections->managesAddress('alice@other.com'), $connections->managesAddress('no-at-sign')];

        // Assert
        $this->assertSame([true, true, false, false], $results);
    }

    public function testADomainBelongsToOneConnection(): void
    {
        // Arrange
        $connections = new SqliteSamlConnections((new TestData())->db);
        $this->add($connections, 'acme', ['acme.com']);

        // Act
        $second = fn () => $this->add($connections, 'beta', ['acme.com']);

        // Assert
        $this->expectException(\PhpSso\Store\DuplicateValue::class);
        $second();
    }

    public function testDeletingAConnectionDisconnectsItsPeopleAndFreesItsDomains(): void
    {
        // Arrange
        $data = new TestData();
        $connections = new SqliteSamlConnections($data->db);
        $id = $this->add($connections, 'acme', ['acme.com']);
        $sub = $data->user('alice');
        (new \PhpSso\Store\Sqlite\SqliteIdentities($data->db))->link($sub, 'saml-' . $id, 'alice-at-acme', 'alice@acme.com');

        // Act
        $connections->delete($id);

        // Assert
        $this->assertNull($connections->find($id));
        $this->assertNull($connections->domainOwner('acme.com'));
        $this->assertSame([], (new \PhpSso\Store\Sqlite\SqliteIdentities($data->db))->forUser($sub));
    }
}
