<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Mfa\Mfa;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Mfa::class, 'verifyMethod')]
#[CoversMethod(Mfa::class, 'verify')]
final class VerifyMethodTest extends TestCase
{
    public function testACodeFromTheAppIsOtp(): void
    {
        // Arrange
        $app = new Support();
        $now = time();
        [$user, $secret] = $app->enrolledUser('alice', false, $now - 120);

        // Act
        $method = $app->mfa->verifyMethod($user['sub'], Totp::code($secret, intdiv($now, 30)), $now);

        // Assert
        self::assertSame('otp', $method);
    }

    public function testARecoveryCodeIsSaidToBeOne(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $codes] = $app->enrolledUser();

        // Act
        $method = $app->mfa->verifyMethod($user['sub'], $codes[0]);

        // Assert
        self::assertSame('recovery', $method);
    }

    public function testAWrongCodeIsNotAMethodAtAll(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();

        // Act
        $methods = [$app->mfa->verifyMethod($user['sub'], '000000'), $app->mfa->verifyMethod($user['sub'], 'not-a-code')];

        // Assert
        self::assertSame([null, null], $methods);
    }

    public function testSomeoneWithoutTheAppHasNone(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('bob');

        // Act
        $method = $app->mfa->verifyMethod($sub, '123456');

        // Assert
        self::assertNull($method);
    }

    public function testVerifyStillAnswersYesOrNo(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $codes] = $app->enrolledUser();

        // Act
        $results = [$app->mfa->verify($user['sub'], $codes[0]), $app->mfa->verify($user['sub'], $codes[0])];

        // Assert
        self::assertSame([true, false], $results); // a recovery code works once
    }
}
