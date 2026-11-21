<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SsoClient;

use PhpSso\OAuth\SsoClient;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\GrantType;

#[CoversMethod(SsoClient::class, 'allowsGrant')]
final class AllowsGrantTest extends TestCase
{
    public function testItAllowsOnlyTheStoredGrants(): void
    {
        // Arrange
        $client = new SsoClient('web', 'Web', [], true, ['authorization_code', 'client_credentials'], null, null, null, []);

        // Act
        $code = $client->allowsGrant(GrantType::AuthorizationCode);
        $machine = $client->allowsGrant(GrantType::ClientCredentials);
        $device = $client->allowsGrant(GrantType::DeviceCode);

        // Assert
        self::assertTrue($code);
        self::assertTrue($machine);
        self::assertFalse($device);
    }
}
