<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\MfaLoginController;

use PhpSso\Auth\MfaLoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support;

#[CoversMethod(MfaLoginController::class, 'challenge')]
#[CoversMethod(MfaLoginController::class, 'challengeForm')]
final class ChallengeTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testARightCodeSignsThePersonIn(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $_SESSION['return_to'] = '/account';
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($user['sub'], $_SESSION['user']);
    }

    public function testARecoveryCodeSignsThePersonInOnce(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $codes] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');
        $first = $app->mfaController->challenge(Support::post(['code' => $codes[0]]));
        $_SESSION = ['csrf' => 'token'];
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $again = $app->mfaController->challenge(Support::post(['code' => $codes[0]]));

        // Assert
        $this->assertSame(302, $first->getStatusCode());
        $this->assertSame(401, $again->getStatusCode());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testAWrongCodeIsRefusedAndRecordedAsAFailedAttempt(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => '000000']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('That code is not right', (string) $response->getBody());
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertCount(1, $app->attempts->failureTimesForIdentifier('alice', $user['sub'], 0));
    }

    public function testTooManyWrongCodesLockTheAccountEvenForTheRightCode(): void
    {
        // Arrange
        $app = new Support(['per_identifier' => 2, 'per_ip' => 100, 'window_seconds' => 900]);
        [$user, $secret] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');
        $app->mfaController->challenge(Support::post(['code' => '000000']));
        $app->mfaController->challenge(Support::post(['code' => '111111']));

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Retry-After'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testNobodyGetsInWithoutHavingProvedAPasswordFirst(): void
    {
        // Arrange
        $app = new Support();
        [, $secret] = $app->enrolledUser();

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testSomeoneJustIdentifiedCannotSkipThePasswordByPostingACode(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->flow->begin('alice', $user['sub']);

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testSomeoneWhoMustSetTheAppUpIsSentThereInsteadOfAskedForACode(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');

        // Act
        $response = $app->mfaController->challengeForm();

        // Assert
        $this->assertSame('/login/mfa/setup', $response->getHeaderLine('Location'));
    }

    public function testItRefusesAWrongCsrfToken(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret), '_csrf' => 'forged']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testThePageIsShownToSomeoneWaitingForTheirCode(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $response = $app->mfaController->challengeForm();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('action="/login/mfa"', (string) $response->getBody());
    }

    public function testARightCodeStillLeadsToTheRequiredStepWhenSomethingIsRequired(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->data->users->setMustChangePassword($user['sub'], true);
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');

        // Act
        $response = $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame('/login/required', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame($user['sub'], $app->flow->actionsPending()['sub']);
    }
}
