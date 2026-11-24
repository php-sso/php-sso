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

#[CoversMethod(StoredRegistrations::class, 'save')]
#[CoversMethod(StoredRegistrations::class, 'find')]
final class SaveTest extends TestCase
{
    private function registration(string $id = 'abcdef123456', ?string $name = 'My MCP client'): ClientRegistration
    {
        return new ClientRegistration(
            $id,
            new ClientMetadata(
                redirectUris: ['http://127.0.0.1:8123/cb'], responseTypes: ['code'], grantTypes: [GrantType::AuthorizationCode, GrantType::RefreshToken],
                tokenEndpointAuthMethod: 'none', scopes: ['openid', 'profile'], clientName: $name, clientUri: 'https://app.example',
                contacts: ['ops@app.example'], softwareId: 'sw-1', backchannelLogoutUri: 'https://app.example/logout', postLogoutRedirectUris: ['https://app.example/bye'],
            ),
            null,
            hash('sha256', 'registration-token'),
            1790000000,
            'registrar',
        );
    }

    public function testARegistrationComesBackAsItWasSaved(): void
    {
        // Arrange
        $store = new StoredRegistrations((new TestData())->clients);
        $registration = $this->registration();

        // Act
        $store->save($registration);
        $found = $store->find('abcdef123456');

        // Assert
        self::assertEquals($registration, $found);
    }

    public function testTheClientShowsUpAmongTheClientsOfTheAdminPanel(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        (new StoredRegistrations($data->clients))->save($this->registration());

        // Assert
        $row = $data->clients->find('abcdef123456');
        self::assertNotNull($row);
        self::assertSame('My MCP client', $row['name']);
        self::assertFalse($row['confidential']);
        self::assertSame(['authorization_code', 'refresh_token'], $row['grants']);
        self::assertSame(['openid', 'profile'], $row['scopes']);
        self::assertFalse($row['restricted']);
    }

    public function testAClientWithoutANameGetsOneAndKeepsHavingNone(): void
    {
        // Arrange
        $data = new TestData();
        $store = new StoredRegistrations($data->clients);

        // Act
        $store->save($this->registration(name: null));

        // Assert
        self::assertSame('Registered client abcdef12', $data->clients->find('abcdef123456')['name'] ?? null);
        self::assertNull($store->find('abcdef123456')?->metadata->clientName);
    }

    public function testSavingAgainReplacesTheRegistration(): void
    {
        // Arrange
        $store = new StoredRegistrations((new TestData())->clients);
        $store->save($this->registration());
        $first = $this->registration();

        // Act
        $store->save($first->withMetadata(new ClientMetadata(['https://new.example/cb'], ['code'], [GrantType::AuthorizationCode], 'client_secret_basic', ['openid'], 'Renamed'), 'hash'));

        // Assert
        $found = $store->find('abcdef123456');
        self::assertSame(['https://new.example/cb'], $found?->redirectUris());
        self::assertSame('Renamed', $found?->metadata->clientName);
        self::assertTrue($found?->isConfidential());
        self::assertSame('hash', $found->secretHash);
    }

    public function testItNeverReplacesAClientAnAdministratorMade(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('abcdef123456');
        $store = new StoredRegistrations($data->clients);
        $this->expectException(LogicException::class);

        // Act
        $store->save($this->registration());
    }
}
