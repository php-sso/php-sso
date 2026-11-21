<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\BrowserErrors;

use PhpSso\OAuth\BrowserErrors;
use PhpSso\Pages;
use PhpSso\OAuthServer\Exception\OAuthException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(BrowserErrors::class, 'respond')]
final class RespondTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        $this->theme = sys_get_temp_dir() . '/sso-theme-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->theme . '/templates/error.html.twig');
        @rmdir($this->theme . '/templates');
        @rmdir($this->theme);
    }

    public function testItShowsTheErrorAsAPageWithTheStatusOfTheError(): void
    {
        // Arrange
        $responder = new BrowserErrors($this->pages());

        // Act
        $response = $responder->respond(OAuthException::invalidRequest('The redirect_uri is not registered for this client.'));

        // Assert
        $body = (string) $response->getBody();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('The redirect_uri is not registered for this client.', $body);
        self::assertStringContainsString('invalid_request', $body);
        self::assertStringNotContainsString('{', $body);
    }

    public function testItEscapesTheMessage(): void
    {
        // Arrange
        $responder = new BrowserErrors($this->pages());

        // Act
        $response = $responder->respond(OAuthException::invalidRequest('<script>alert(1)</script>'));

        // Assert
        self::assertStringNotContainsString('<script>alert', (string) $response->getBody());
    }

    public function testAThemeCanReplaceThePage(): void
    {
        // Arrange
        mkdir($this->theme . '/templates', 0777, true);
        file_put_contents($this->theme . '/templates/error.html.twig', 'THEMED {{ code }}');
        $responder = new BrowserErrors($this->pages());

        // Act
        $response = $responder->respond(OAuthException::invalidRequest('x'));

        // Assert
        self::assertSame('THEMED invalid_request', (string) $response->getBody());
    }

    public function testThePageIsNeverCached(): void
    {
        // Arrange
        $responder = new BrowserErrors($this->pages());

        // Act
        $response = $responder->respond(OAuthException::invalidRequest('x'));

        // Assert
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    private function pages(): Pages
    {
        return new Pages(dirname(__DIR__, 5) . '/templates/pages', $this->theme, sys_get_temp_dir() . '/sso-cache-' . bin2hex(random_bytes(4)), dirname(__DIR__, 5) . '/public', ['app_name' => 'Test'], false);
    }
}
