<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\OAuthServer\Encoding\Base64Url;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SocialLogin::class, 'begin')]
#[CoversMethod(SocialLogin::class, 'redirectUri')]
#[CoversMethod(SocialLogin::class, 'pendingIntent')]
final class BeginTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItReturnsTheProvidersAddressAndKeepsStateNonceAndVerifierInTheSession(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $url = $app->social->begin('fake');

        // Assert
        $sent = $app->provider->lastAuthorization;
        $this->assertSame('https://idp.test/authorize?state=' . $sent['state'], $url);
        $this->assertSame('https://sso.example.com/login/social/fake/callback', $sent['redirect_uri']);
        $this->assertSame([$sent['state'], $sent['nonce']], [$_SESSION['social']['state'], $_SESSION['social']['nonce']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $sent['state']);
    }

    public function testThePkceChallengeIsTheHashOfTheVerifierKeptInTheSession(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $app->social->begin('fake');

        // Assert
        $expected = Base64Url::encode(hash('sha256', $_SESSION['social']['verifier'], true));
        $this->assertSame($expected, $app->provider->lastAuthorization['code_challenge']);
    }

    public function testEveryRoundTripGetsItsOwnState(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');
        $first = $app->provider->lastAuthorization['state'];

        // Act
        $app->social->begin('fake');

        // Assert
        $this->assertNotSame($first, $app->provider->lastAuthorization['state']);
    }

    public function testItRefusesAProviderThatIsNotAvailable(): void
    {
        // Arrange
        $app = new Support();
        $this->expectException(SocialLoginException::class);

        // Act
        $app->social->begin('nonexistent');

        // Assert (the exception above)
    }

    public function testItRemembersWhetherThePersonWasSigningInOrConnecting(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake', 'link', 'sub-9');

        // Act
        $intent = $app->social->pendingIntent();

        // Assert
        $this->assertSame('link', $intent);
        $this->assertSame('login', (function (): string { $_SESSION = []; return (new Support())->social->pendingIntent(); })());
    }
}
