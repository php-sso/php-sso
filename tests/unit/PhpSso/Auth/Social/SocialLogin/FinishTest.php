<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SocialLogin::class, 'finish')]
final class FinishTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function failureOf(callable $action): ?SocialLoginException
    {
        try {
            $action();
        } catch (SocialLoginException $e) {
            return $e;
        }

        return null;
    }

    public function testAWrongStateIsRefusedWithoutAskingTheProviderForAnything(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => 'forged', 'code' => 'c']));

        // Assert
        $this->assertNotNull($failure);
        $this->assertSame([], $app->provider->identifyCalls);
    }

    public function testACallbackWithNoRoundTripBehindItIsRefused(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => 'anything', 'code' => 'c']));

        // Assert
        $this->assertNotNull($failure);
        $this->assertSame([], $app->provider->identifyCalls);
    }

    public function testARoundTripWorksOnlyOnce(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);
        $app->roundTrip();
        $state = $app->provider->lastAuthorization['state'];

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $state, 'code' => 'code-2']));

        // Assert
        $this->assertNotNull($failure);
        $this->assertCount(1, $app->provider->identifyCalls);
    }

    public function testAnExpiredRoundTripIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');
        $_SESSION['social']['expires'] = time() - 1;

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state'], 'code' => 'c']));

        // Assert
        $this->assertNotNull($failure);
        $this->assertSame([], $app->provider->identifyCalls);
    }

    public function testACallbackForAnotherProviderThanTheOneStartedIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');
        $_SESSION['social']['provider'] = 'github';

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state'], 'code' => 'c']));

        // Assert
        $this->assertNotNull($failure);
    }

    public function testAProviderErrorIsReportedAsCancelledAndNothingIsAsked(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state'], 'error' => 'access_denied']));

        // Assert
        $this->assertStringContainsString('Fake', (string) $failure?->getMessage());
        $this->assertStringContainsString('access_denied', (string) $failure?->detail());
        $this->assertSame([], $app->provider->identifyCalls);
    }

    public function testACallbackWithoutACodeIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $app->social->begin('fake');

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state']]));

        // Assert
        $this->assertNotNull($failure);
    }

    public function testItHandsTheCodeTheVerifierAndTheNonceOfThisRoundTripToTheProvider(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);
        $app->social->begin('fake');
        $verifier = $_SESSION['social']['verifier'];
        $nonce = $app->provider->lastAuthorization['nonce'];

        // Act
        $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state'], 'code' => 'the-code']);

        // Assert
        $this->assertSame(['redirect_uri' => 'https://sso.example.com/login/social/fake/callback', 'code' => 'the-code', 'verifier' => $verifier, 'nonce' => $nonce], $app->provider->identifyCalls[0]);
    }

    public function testWhatTheProviderRefusesIsPassedOn(): void
    {
        // Arrange
        $app = new Support();
        $app->provider->failure = new SocialLoginException('The provider refused the request.', 'invalid_grant');
        $app->social->begin('fake');

        // Act
        $failure = $this->failureOf(fn () => $app->social->finish('fake', ['state' => $app->provider->lastAuthorization['state'], 'code' => 'c']));

        // Assert
        $this->assertSame('The provider refused the request.', $failure?->getMessage());
    }
}
