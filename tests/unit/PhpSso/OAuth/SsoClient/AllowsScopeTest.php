<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SsoClient;

use PhpSso\OAuth\SsoClient;
use PhpSso\Store\ScopeName;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SsoClient::class, 'allowsScope')]
final class AllowsScopeTest extends TestCase
{
    /** @param list<string>|null $scopes */
    private function client(?array $scopes, bool $mayRegister): SsoClient
    {
        return new SsoClient('app', 'App', [], true, ['client_credentials'], null, $scopes, null, [], $mayRegister);
    }

    public function testAClientThatMayRegisterAppsMayAskForTheRegistrationScope(): void
    {
        // Arrange
        $client = $this->client(['api'], mayRegister: true);

        // Act
        $allowed = $client->allowsScope(ScopeName::REGISTER);

        // Assert
        $this->assertTrue($allowed);
    }

    public function testAListOfScopesNeverGrantsTheRegistrationScope(): void
    {
        // Arrange
        $client = $this->client([ScopeName::REGISTER, 'api'], mayRegister: false);

        // Act
        $allowed = $client->allowsScope(ScopeName::REGISTER);

        // Assert
        $this->assertFalse($allowed);
    }

    public function testAClientWithoutAListOfItsOwnStillNeedsTheSetting(): void
    {
        // Arrange
        $client = $this->client(null, mayRegister: false);

        // Act
        $allowed = $client->allowsScope(ScopeName::REGISTER);

        // Assert
        $this->assertFalse($allowed);
    }

    public function testOtherScopesFollowTheListAsBefore(): void
    {
        // Arrange
        $client = $this->client(['api'], mayRegister: true);

        // Act
        $results = [$client->allowsScope('api'), $client->allowsScope('openid')];

        // Assert
        $this->assertSame([true, false], $results);
    }
}
