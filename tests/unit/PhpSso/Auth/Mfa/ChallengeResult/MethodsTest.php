<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\ChallengeResult;

use PhpSso\Auth\Mfa\ChallengeResult;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ChallengeResult::class, 'methods')]
final class MethodsTest extends TestCase
{
    public function testACodeFromTheAppIsAOneTimePasswordAndASecondFactor(): void
    {
        // Arrange
        $result = new ChallengeResult(true, null, 'otp');

        // Act
        $methods = $result->methods();

        // Assert
        self::assertSame(['otp', 'mfa'], $methods);
    }

    public function testARecoveryCodeIsASecondFactorButNotAOneTimePasswordFromTheApp(): void
    {
        // Arrange
        $result = new ChallengeResult(true, null, 'recovery');

        // Act
        $methods = $result->methods();

        // Assert
        self::assertSame(['mfa'], $methods);
    }

    public function testNothingWasProvedWhenTheCodeWasNotAccepted(): void
    {
        // Arrange
        $result = new ChallengeResult(false);

        // Act
        $methods = $result->methods();

        // Assert
        self::assertSame([], $methods);
    }
}
