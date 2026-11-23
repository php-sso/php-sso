<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(LoginController::class, 'password')]
final class AuthenticationMethodsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTypingThePasswordIsRecordedAsPwd(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);

        // Act
        $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        self::assertSame(['pwd'], $app->sessions->amr((string) $_SESSION['sid']));
    }

    public function testPasswordThenTheSecondStepIsRecordedInOrder(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->flow->begin('alice', $user['sub']);
        $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Act
        $app->mfaController->challenge(Support::post(['code' => Support::codeFor($secret)]));

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $app->sessions->amr((string) $_SESSION['sid']));
    }
}
