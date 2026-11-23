<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PhpSso\Auth\Social\SocialProviders;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\Social\SocialLogin\FakeProvider;

#[CoversMethod(LoginController::class, 'identifyForm')]
final class SocialButtonsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheLoginPageOffersEachAvailableProvider(): void
    {
        // Arrange
        $social = new SocialProviders(['fake' => new FakeProvider()], [['key' => 'fake', 'label' => 'Fake', 'enabled' => true]]);
        $app = new Support([], 'optional', $social);

        // Act
        $body = (string) $app->controller->identifyForm()->getBody();

        // Assert
        $this->assertStringContainsString('href="/login/social/fake"', $body);
        $this->assertStringContainsString('Continue with Fake', $body);
    }

    public function testTheLoginPageOffersNothingExtraWhenNoProviderIsConfigured(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $body = (string) $app->controller->identifyForm()->getBody();

        // Assert
        $this->assertStringNotContainsString('/login/social/', $body);
    }
}
