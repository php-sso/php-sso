<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Http\SecurityHeaders;

use Laminas\Diactoros\Response;
use PhpSso\Http\SecurityHeaders;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SecurityHeaders::class, 'apply')]
final class ApplyTest extends TestCase
{
    public function testItForbidsFramingSniffingAndLeakingTheAddress(): void
    {
        // Arrange
        $headers = new SecurityHeaders(null, false);

        // Act
        $response = $headers->apply(new Response());

        // Assert
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testItSendsAnotherPolicyWhenOneIsGiven(): void
    {
        // Arrange
        $headers = new SecurityHeaders("default-src 'self' https://fonts.example", false);

        // Act
        $response = $headers->apply(new Response());

        // Assert
        $this->assertSame("default-src 'self' https://fonts.example", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testItAsksBrowsersToInsistOnHttpsOnlyOverHttps(): void
    {
        // Arrange
        $response = new Response();

        // Act
        $overHttps = (new SecurityHeaders(null, true))->apply($response);
        $overHttp = (new SecurityHeaders(null, false))->apply($response);

        // Assert
        $this->assertStringStartsWith('max-age=', $overHttps->getHeaderLine('Strict-Transport-Security'));
        $this->assertFalse($overHttp->hasHeader('Strict-Transport-Security'));
    }

    public function testItKeepsAHeaderTheResponseSetItself(): void
    {
        // Arrange
        $response = (new Response())->withHeader('Cache-Control', 'public, max-age=300');

        // Act
        $applied = (new SecurityHeaders(null, false))->apply($response);

        // Assert
        $this->assertSame('public, max-age=300', $applied->getHeaderLine('Cache-Control'));
    }

    public function testItKeepsPagesOutOfCachesByDefault(): void
    {
        // Arrange
        $response = new Response();

        // Act
        $applied = (new SecurityHeaders(null, false))->apply($response);

        // Assert
        $this->assertSame('no-store', $applied->getHeaderLine('Cache-Control'));
    }
}
