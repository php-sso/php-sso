<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\StoreRequestStore;

use PhpSso\Integration\Saml\StoreRequestStore;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\SamlRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(StoreRequestStore::class, 'consume')]
#[CoversMethod(StoreRequestStore::class, 'remember')]
final class ConsumeTest extends TestCase
{
    public function testARequestComesBackOnceWithWhatWasRemembered(): void
    {
        // Arrange
        $data = new TestData();
        $id = (new SamlConnections($data->db))->create(['name' => 'Acme', 'idp_entity_id' => 'e', 'idp_sso_url' => 'https://x.test', 'idp_certificate' => 'x', 'enabled' => true, 'create_users' => false, 'link_existing' => false], ['acme.com']);
        $store = new StoreRequestStore(new SamlRequests($data->db));
        $store->remember('_r1', $id, hash('sha256', 'secret'), '/account');

        // Act
        $first = $store->consume('_r1');
        $second = $store->consume('_r1');

        // Assert
        $this->assertNotNull($first);
        $this->assertSame([$id, hash('sha256', 'secret'), '/account'], [$first->connectionId, $first->bindingHash, $first->returnTo]);
        $this->assertNull($second);
    }

    public function testARequestNobodyMadeIsNull(): void
    {
        // Arrange
        $store = new StoreRequestStore(new SamlRequests((new TestData())->db));

        // Act
        $request = $store->consume('_never');

        // Assert
        $this->assertNull($request);
    }
}
