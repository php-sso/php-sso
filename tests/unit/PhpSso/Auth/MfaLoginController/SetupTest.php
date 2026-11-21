<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\MfaLoginController;

use PhpSso\Auth\MfaLoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support;

#[CoversMethod(MfaLoginController::class, 'setupForm')]
#[CoversMethod(MfaLoginController::class, 'setup')]
#[CoversMethod(MfaLoginController::class, 'finish')]
final class SetupTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItShowsTheKeyToSomeoneWhoHasToSetTheAppUp(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');

        // Act
        $response = $app->mfaController->setupForm();

        // Assert
        $secret = $_SESSION['login']['enroll_secret'];
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(trim(chunk_split($secret, 4, ' ')), (string) $response->getBody());
        $this->assertStringContainsString('otpauth://totp/', (string) $response->getBody());
    }

    public function testARightCodeTurnsTheAppOnShowsRecoveryCodesAndSignsInOnlyAfterContinuing(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');
        $app->mfaController->setupForm();
        $secret = $_SESSION['login']['enroll_secret'];

        // Act
        $shown = $app->mfaController->setup(Support::post(['code' => Support::codeFor($secret)]));
        $signedInYet = isset($_SESSION['user']);
        $finished = $app->mfaController->finish(Support::post([]));

        // Assert
        $this->assertSame(200, $shown->getStatusCode());
        $this->assertStringContainsString('Your recovery codes', (string) $shown->getBody());
        $this->assertTrue($app->mfa->isEnrolled($sub));
        $this->assertFalse($signedInYet);
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertSame(302, $finished->getStatusCode());
    }

    public function testAWrongCodeDoesNotTurnTheAppOn(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');
        $app->mfaController->setupForm();

        // Act
        $response = $app->mfaController->setup(Support::post(['code' => '000000']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($app->mfa->isEnrolled($sub));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testNobodyCanFinishWithoutSettingTheAppUp(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll');

        // Act
        $response = $app->mfaController->finish(Support::post([]));

        // Assert
        $this->assertSame('/login/mfa/setup', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testSomeoneWhoAlreadyHasTheAppIsSentToTheCodePageInstead(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'enroll');

        // Act
        $response = $app->mfaController->setupForm();

        // Assert
        $this->assertSame('/login/mfa', $response->getHeaderLine('Location'));
        $this->assertSame('challenge', $app->flow->secondFactorPending()['step']);
    }

    public function testSomeoneWhoWasAskedForACodeCannotUseTheSetupPageToChooseTheirOwnApp(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge');
        $_SESSION['login']['enroll_secret'] = $app->mfa->newSecret();
        $secret = $_SESSION['login']['enroll_secret'];

        // Act
        $response = $app->mfaController->setup(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        $this->assertSame('/login/mfa', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }
}
