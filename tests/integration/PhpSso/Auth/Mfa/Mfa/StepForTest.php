<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Mfa\Mfa;

use PhpSso\Auth\Mfa\Mfa;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Mfa::class, 'stepFor')]
#[CoversMethod(Mfa::class, 'requiredFor')]
#[CoversMethod(Mfa::class, 'policy')]
#[CoversMethod(Mfa::class, 'savePolicy')]
final class StepForTest extends TestCase
{
    /** @return array<string, array{string, bool, bool, string}> policy, is admin, enrolled, step */
    public static function cases(): array
    {
        return [
            'optional, not enrolled' => ['optional', false, false, 'none'],
            'optional, enrolled' => ['optional', false, true, 'challenge'],
            'admins, an admin not enrolled' => ['admins', true, false, 'enroll'],
            'admins, an admin enrolled' => ['admins', true, true, 'challenge'],
            'admins, someone else not enrolled' => ['admins', false, false, 'none'],
            'everyone, not enrolled' => ['everyone', false, false, 'enroll'],
            'everyone, enrolled' => ['everyone', false, true, 'challenge'],
        ];
    }

    #[DataProvider('cases')]
    public function testItDecidesWhatHappensAfterThePassword(string $policy, bool $admin, bool $enrolled, string $expected): void
    {
        // Arrange
        $app = new Support($policy);
        if ($enrolled) {
            [$user] = $app->enrolledUser('alice', $admin);
        } else {
            $user = $app->data->users->find($app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@example.com', 'email_verified' => true, 'is_admin' => $admin]));
        }

        // Act
        $step = $app->mfa->stepFor($user);

        // Assert
        $this->assertSame($expected, $step);
    }

    public function testASavedPolicyWinsOverTheConfigValue(): void
    {
        // Arrange
        $app = new Support('optional');

        // Act
        $saved = $app->mfa->savePolicy('everyone');

        // Assert
        $this->assertTrue($saved);
        $this->assertSame('everyone', $app->mfa->policy());
    }

    public function testItRefusesAPolicyThatDoesNotExistAndFallsBackToOptional(): void
    {
        // Arrange
        $app = new Support('nonsense');

        // Act
        $saved = $app->mfa->savePolicy('sometimes');

        // Assert
        $this->assertFalse($saved);
        $this->assertSame('optional', $app->mfa->policy());
    }
}
