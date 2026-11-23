<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Mfa\Mfa;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Mfa::class, 'enroll')]
#[CoversMethod(Mfa::class, 'regenerateRecoveryCodes')]
#[CoversMethod(Mfa::class, 'disable')]
#[CoversMethod(Mfa::class, 'otpauthUri')]
final class EnrollTest extends TestCase
{
    public function testItTurnsTheAppOnWithACorrectCodeAndReturnsEightRecoveryCodes(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->data->users->find($app->data->user('alice'));
        $secret = $app->mfa->newSecret();

        // Act
        $codes = $app->mfa->enroll($user, $secret, Totp::code($secret, intdiv(time(), 30)));

        // Assert
        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){3}$/', $codes[0]);
        $this->assertTrue($app->mfa->isEnrolled($user['sub']));
        $this->assertSame(8, $app->mfa->recoveryCodesLeft($user['sub']));
    }

    public function testItDoesNotTurnTheAppOnWithAWrongCode(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->data->users->find($app->data->user('alice'));

        // Act
        $codes = $app->mfa->enroll($user, $app->mfa->newSecret(), '000000');

        // Assert
        $this->assertNull($codes);
        $this->assertFalse($app->mfa->isEnrolled($user['sub']));
    }

    public function testTheSecretIsStoredSealedNotInTheClear(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();

        // Act
        $stored = $app->factors->find($user['sub'])['secret'];

        // Assert
        $this->assertStringNotContainsString($secret, $stored);
    }

    public function testNewRecoveryCodesReplaceTheOldOnes(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $old] = $app->enrolledUser();

        // Act
        $new = $app->mfa->regenerateRecoveryCodes($user['sub']);

        // Assert
        $this->assertFalse($app->mfa->verify($user['sub'], $old[0]));
        $this->assertTrue($app->mfa->verify($user['sub'], $new[0]));
    }

    public function testDisablingRemovesTheAppAndTheCodes(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();

        // Act
        $app->mfa->disable($user['sub']);

        // Assert
        $this->assertFalse($app->mfa->isEnrolled($user['sub']));
        $this->assertSame(0, $app->mfa->recoveryCodesLeft($user['sub']));
    }

    public function testTheAddressForTheAppNamesTheIssuerAndTheUsername(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->data->users->find($app->data->user('alice'));

        // Act
        $uri = $app->mfa->otpauthUri($user, 'GEZDGNBVGY3TQOJQ');

        // Assert
        $this->assertStringStartsWith('otpauth://totp/Test%20SSO%3Aalice?secret=GEZDGNBVGY3TQOJQ', $uri);
    }
}
