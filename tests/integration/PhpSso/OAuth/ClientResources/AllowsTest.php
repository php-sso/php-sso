<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\ClientResources;

use PhpSso\OAuth\ClientResources;
use PhpSso\OAuth\SsoClient;
use PhpSso\OAuth\StoredRegistrations;
use PhpSso\OAuthServer\Grant\GrantType;
use PhpSso\OAuthServer\Registration\ClientMetadata;
use PhpSso\OAuthServer\Registration\ClientRegistration;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(ClientResources::class, 'allows')]
final class AllowsTest extends TestCase
{
    private function client(string $id): SsoClient
    {
        return new SsoClient($id, $id, [], false, ['authorization_code'], null, null, null, []);
    }

    public function testAClientMayGetTokensForTheResourcesOnItsOwnList(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('web');
        $data->clients->setResources('web', ['https://api.example/']);
        $policy = new ClientResources($data->clients);

        // Act
        $results = [$policy->allows($this->client('web'), 'https://api.example/'), $policy->allows($this->client('web'), 'https://other.example/')];

        // Assert
        self::assertSame([true, false], $results);
    }

    public function testMatchingIsExact(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('web');
        $data->clients->setResources('web', ['https://api.example/']);

        // Act
        $allowed = (new ClientResources($data->clients))->allows($this->client('web'), 'https://api.example/v1');

        // Assert
        self::assertFalse($allowed);
    }

    public function testAClientWithoutAListMayNotGetAnyResource(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('web');

        // Act
        $allowed = (new ClientResources($data->clients))->allows($this->client('web'), 'https://api.example/');

        // Assert
        self::assertFalse($allowed);
    }

    public function testAnAppThatRegisteredItselfGetsTheResourcesOfItsRegistrar(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $data->clients->setRegistrar('backend', ['openid'], ['https://mcp.example/']);
        (new StoredRegistrations($data->clients))->save(new ClientRegistration(
            'selfmade', new ClientMetadata(['https://a.example/cb'], ['code'], [GrantType::AuthorizationCode], 'none', ['openid']), null, 'x', 1, 'backend',
        ));
        $policy = new ClientResources($data->clients);

        // Act
        $results = [$policy->allows($this->client('selfmade'), 'https://mcp.example/'), $policy->allows($this->client('selfmade'), 'https://other.example/')];

        // Assert
        self::assertSame([true, false], $results);
    }

    public function testTheRegistrarsResourcesAreNotHandedToItsOwnToken(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $data->clients->setRegistrar('backend', ['openid'], ['https://mcp.example/']);

        // Act
        $allowed = (new ClientResources($data->clients))->allows($this->client('backend'), 'https://mcp.example/');

        // Assert
        self::assertFalse($allowed);
    }

    public function testAnUnknownClientMayNotGetAnyResource(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $allowed = (new ClientResources($data->clients))->allows($this->client('ghost'), 'https://api.example/');

        // Assert
        self::assertFalse($allowed);
    }
}
