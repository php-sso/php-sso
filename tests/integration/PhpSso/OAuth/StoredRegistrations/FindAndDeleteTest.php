<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\StoredRegistrations;

use LogicException;
use PhpSso\OAuth\StoredRegistrations;
use PhpSso\OAuthServer\Grant\GrantType;
use PhpSso\OAuthServer\Registration\ClientMetadata;
use PhpSso\OAuthServer\Registration\ClientRegistration;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(StoredRegistrations::class, 'find')]
#[CoversMethod(StoredRegistrations::class, 'delete')]
final class FindAndDeleteTest extends TestCase
{
    public function testItDoesNotFindAClientAnAdministratorMade(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('admin-made');

        // Act
        $found = (new StoredRegistrations($data->clients))->find('admin-made');

        // Assert
        self::assertNull($found);
    }

    public function testItDeletesARegisteredClient(): void
    {
        // Arrange
        $data = new TestData();
        $store = new StoredRegistrations($data->clients);
        $store->save(new ClientRegistration('reg-1', new ClientMetadata(['https://a.example/cb'], ['code'], [GrantType::AuthorizationCode], 'none', ['openid']), null, 'x', 1));

        // Act
        $store->delete('reg-1');

        // Assert
        self::assertNull($data->clients->find('reg-1'));
    }

    public function testItNeverDeletesAClientAnAdministratorMade(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('admin-made');
        $this->expectException(LogicException::class);

        // Act
        try {
            (new StoredRegistrations($data->clients))->delete('admin-made');
        } finally {
            self::assertNotNull($data->clients->find('admin-made'));
        }
    }
}
