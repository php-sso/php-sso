<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\MfaLoginController;

use PhpSso\Auth\MfaLoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Auth\LoginController\Support;

#[CoversMethod(MfaLoginController::class, 'challenge')]
#[CoversMethod(MfaLoginController::class, 'setup')]
#[CoversMethod(MfaLoginController::class, 'finish')]
final class AuthenticationMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return list<string>|null */
    private function recorded(Support $app): ?array
    {
        return $app->sessions->amr((string) $_SESSION['sid']);
    }

    public function testACodeFromTheAppAfterAPasswordIsPwdOtpMfa(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge', ['pwd']);

        // Act
        $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $this->recorded($app));
    }

    public function testARecoveryCodeIsOnlyMfaOnTopOfThePassword(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $codes] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge', ['pwd']);

        // Act
        $app->mfaController->challenge(Support::post(['code' => $codes[0]]));

        // Assert
        self::assertSame(['pwd', 'mfa'], $this->recorded($app));
    }

    public function testSettingTheAppUpAtSignInCountsAsHavingUsedIt(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->beginSecondFactor($sub, 'alice', 'enroll', ['pwd']);
        $app->mfaController->setupForm();
        $secret = $_SESSION['login']['enroll_secret'];
        $app->mfaController->setup(Support::post(['code' => Support::codeFor($secret)]));

        // Act
        $app->mfaController->finish(Support::post([]));

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $this->recorded($app));
    }

    public function testAWrongCodeSignsNobodyInAndRecordsNothing(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->beginSecondFactor($user['sub'], 'alice', 'challenge', ['pwd']);

        // Act
        $app->mfaController->challenge(Support::post(['code' => '000000']));

        // Assert
        self::assertArrayNotHasKey('sid', $_SESSION);
    }
}
