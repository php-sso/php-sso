<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\SamlRequests;

use PhpSso\Store\SamlConnections;
use PhpSso\Store\SamlRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(SamlRequests::class, 'consume')]
#[CoversMethod(SamlRequests::class, 'remember')]
final class ConsumeTest extends TestCase
{
    private function setup2(): array
    {
        $data = new TestData();
        $id = (new SamlConnections($data->db))->create(['name' => 'Acme', 'idp_entity_id' => 'e', 'idp_sso_url' => 'https://x.test', 'idp_certificate' => 'x', 'enabled' => true, 'create_users' => false, 'link_existing' => false], ['acme.com']);

        return [new SamlRequests($data->db), $id];
    }

    public function testARequestWorksOnceAndKeepsTheHashItWasGivenOfTheBrowserSecret(): void
    {
        // Arrange
        [$requests, $id] = $this->setup2();
        $requests->remember('_r1', $id, hash('sha256', 'secret'), '/account');

        // Act
        $first = $requests->consume('_r1');
        $second = $requests->consume('_r1');

        // Assert
        $this->assertSame([$id, hash('sha256', 'secret'), '/account'], [$first['connection_id'], $first['binding_hash'], $first['return_to']]);
        $this->assertNull($second);
    }

    public function testAnOldRequestIsNotAccepted(): void
    {
        // Arrange
        [$requests, $id] = $this->setup2();
        $requests->remember('_r1', $id, hash('sha256', 'secret'), null, 1000);

        // Act
        $result = $requests->consume('_r1', 1000 + 601);

        // Assert
        $this->assertNull($result);
    }

    public function testAnUnknownRequestIsNotAccepted(): void
    {
        // Arrange
        [$requests] = $this->setup2();

        // Act
        $result = $requests->consume('_nope');

        // Assert
        $this->assertNull($result);
    }
}
