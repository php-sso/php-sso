<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\SamlController;

use PhpSso\Auth\SamlController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\Saml\IdpFixture;

#[CoversMethod(SamlController::class, 'start')]
#[CoversMethod(SamlController::class, 'metadata')]
final class StartTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheBrowserIsSentToTheCompanyWithABindingCookie(): void
    {
        // Arrange
        $app = new Support();
        $_SESSION['return_to'] = '/account';

        // Act
        $response = $app->controller->start($app->saml->id);

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertStringStartsWith(IdpFixture::SSO_URL . '?SAMLRequest=', $response->getHeaderLine('Location'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('sso_saml=', $cookie);
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('Secure; SameSite=None', $cookie);
        $this->assertStringContainsString('Path=/saml', $cookie);
    }

    public function testOverPlainHttpTheCookieIsLaxForDevelopment(): void
    {
        // Arrange
        $app = new Support('http://sso.test');

        // Act
        $response = $app->controller->start($app->saml->id);

        // Assert
        $this->assertStringContainsString('SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        $this->assertStringNotContainsString('Secure', $response->getHeaderLine('Set-Cookie'));
    }

    public function testAnUnknownConnectionSendsThePersonBackWithAMessage(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->start(999);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame('Signing in through your organisation is not available.', $_SESSION['login_error']);
    }

    public function testASwitchedOffConnectionDoesNotStart(): void
    {
        // Arrange
        $app = new Support('https://sso.test', false);

        // Act
        $response = $app->controller->start($app->saml->id);

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayHasKey('login_error', $_SESSION);
    }

    public function testTheMetadataIsPublicXmlAndAnUnknownConnectionIsNotFound(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $known = $app->controller->metadata($app->saml->id);
        $unknown = $app->controller->metadata(999);

        // Assert
        $this->assertSame(200, $known->getStatusCode());
        $this->assertStringContainsString('EntityDescriptor', (string) $known->getBody());
        $this->assertSame(404, $unknown->getStatusCode());
    }
}
