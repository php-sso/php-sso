<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SsoClient;

use PhpSso\OAuth\SsoClient;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SsoClient::class, 'allowsScope')]
final class AllowsScopeTest extends TestCase
{
    public function testAClientWithoutAScopeListMayAskForAnything(): void
    {
        // Arrange
        $client = new SsoClient('web', 'Web', [], true, [], null, null, null, []);

        // Act
        $allowed = $client->allowsScope('groups');

        // Assert
        self::assertTrue($allowed);
    }

    public function testAClientWithAScopeListIsLimitedToIt(): void
    {
        // Arrange
        $client = new SsoClient('web', 'Web', [], true, [], null, ['openid', 'profile'], null, []);

        // Act
        $profile = $client->allowsScope('profile');
        $groups = $client->allowsScope('groups');

        // Assert
        self::assertTrue($profile);
        self::assertFalse($groups);
    }
}
