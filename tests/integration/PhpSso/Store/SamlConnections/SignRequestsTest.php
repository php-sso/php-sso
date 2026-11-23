<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\SamlConnections;

use PhpSso\Store\SamlConnections;
use PhpSso\Store\Sql\SqlSamlConnections;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(SamlConnections::class, 'create')]
#[CoversMethod(SamlConnections::class, 'update')]
final class SignRequestsTest extends TestCase
{
    private function data(bool $sign): array
    {
        return ['name' => 'Acme', 'idp_entity_id' => 'https://idp.acme.test/e', 'idp_sso_url' => 'https://idp.acme.test/sso', 'idp_certificate' => 'x', 'enabled' => true, 'create_users' => false, 'link_existing' => false, 'sign_requests' => $sign];
    }

    public function testASignedConnectionIsStoredAsSigned(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);

        // Act
        $id = $connections->create($this->data(true), ['acme.com']);

        // Assert
        $this->assertSame(1, (int) $connections->find($id)['sign_requests']);
    }

    public function testASavedConnectionThatDoesNotMentionItIsNotSigned(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);
        $data = $this->data(false);
        unset($data['sign_requests']);

        // Act
        $id = $connections->create($data, ['acme.com']);

        // Assert
        $this->assertSame(0, (int) $connections->find($id)['sign_requests']);
    }

    public function testItCanBeTurnedOnAndOffAgain(): void
    {
        // Arrange
        $connections = new SqlSamlConnections((new TestData())->db);
        $id = $connections->create($this->data(false), ['acme.com']);

        // Act
        $connections->update($id, $this->data(true), ['acme.com']);
        $on = (int) $connections->find($id)['sign_requests'];
        $connections->update($id, $this->data(false), ['acme.com']);
        $off = (int) $connections->find($id)['sign_requests'];

        // Assert
        $this->assertSame([1, 0], [$on, $off]);
    }
}
