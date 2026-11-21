<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\OidcProvider;

use PhpSso\Auth\Social\OidcProvider;
use PhpSso\Auth\Social\SocialLoginException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(OidcProvider::class, 'authorizationUrl')]
final class AuthorizationUrlTest extends TestCase
{
    public function testItSendsTheBrowserToTheDiscoveredEndpointWithStatePkceAndNonce(): void
    {
        // Arrange
        $idp = new Fixture();

        // Act
        $url = $idp->provider->authorizationUrl(Fixture::REDIRECT, 'state-1', 'nonce-1', 'challenge-1');

        // Assert
        $this->assertStringStartsWith('https://idp.example.com/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(
            ['response_type' => 'code', 'client_id' => Fixture::CLIENT_ID, 'redirect_uri' => Fixture::REDIRECT, 'scope' => 'openid email profile', 'state' => 'state-1', 'nonce' => 'nonce-1', 'code_challenge' => 'challenge-1', 'code_challenge_method' => 'S256'],
            $query,
        );
    }

    public function testItRefusesAProviderThatNamesItselfDifferentlyThanConfigured(): void
    {
        // Arrange
        $idp = new Fixture(['issuer' => 'https://evil.example.com']);
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->authorizationUrl(Fixture::REDIRECT, 's', 'n', 'c');

        // Assert (the exception above)
    }

    public function testItRefusesAnEndpointThatIsNotHttps(): void
    {
        // Arrange
        $idp = new Fixture(['authorization_endpoint' => 'http://idp.example.com/authorize']);
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->authorizationUrl(Fixture::REDIRECT, 's', 'n', 'c');

        // Assert (the exception above)
    }

    public function testItAcceptsPlainHttpOnlyWhenToldTo(): void
    {
        // Arrange
        $idp = new Fixture(['authorization_endpoint' => 'http://idp.example.com/authorize'], allowHttp: true);

        // Act
        $url = $idp->provider->authorizationUrl(Fixture::REDIRECT, 's', 'n', 'c');

        // Assert
        $this->assertStringStartsWith('http://idp.example.com/authorize?', $url);
    }
}
