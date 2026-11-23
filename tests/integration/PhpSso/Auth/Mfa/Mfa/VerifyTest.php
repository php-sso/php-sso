<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Mfa\Mfa;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Mfa::class, 'verify')]
final class VerifyTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testItAcceptsACodeFromTheAppOnlyOnce(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser('alice', false, self::NOW - 120);
        $code = Totp::code($secret, intdiv(self::NOW, 30));

        // Act
        $first = $app->mfa->verify($user['sub'], $code, self::NOW);
        $replay = $app->mfa->verify($user['sub'], $code, self::NOW);

        // Assert
        $this->assertTrue($first);
        $this->assertFalse($replay);
    }

    public function testItRejectsAWrongCodeAndInputThatIsNeitherKind(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();

        // Act
        $wrong = $app->mfa->verify($user['sub'], '123456');
        $junk = $app->mfa->verify($user['sub'], 'hello');

        // Assert
        $this->assertFalse($wrong);
        $this->assertFalse($junk);
    }

    public function testItAcceptsARecoveryCodeOnceIgnoringCaseAndDashes(): void
    {
        // Arrange
        $app = new Support();
        [$user, , $codes] = $app->enrolledUser();
        $typed = strtolower(str_replace('-', ' ', $codes[2]));

        // Act
        $first = $app->mfa->verify($user['sub'], $typed);
        $replay = $app->mfa->verify($user['sub'], $codes[2]);

        // Assert
        $this->assertTrue($first);
        $this->assertFalse($replay);
        $this->assertSame(7, $app->mfa->recoveryCodesLeft($user['sub']));
    }

    public function testItRejectsEverythingForSomeoneWithoutTheApp(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');

        // Act
        $accepted = $app->mfa->verify($sub, '123456');

        // Assert
        $this->assertFalse($accepted);
    }

    public function testItRejectsACodeWhenTheSecretCannotBeDecrypted(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $app->data->db->pdo->prepare('UPDATE user_mfa SET secret = ? WHERE user_sub = ?')->execute(['garbage', $user['sub']]);

        // Act
        $accepted = $app->mfa->verify($user['sub'], Totp::code($secret, intdiv(time(), 30)));

        // Assert
        $this->assertFalse($accepted);
    }
}
