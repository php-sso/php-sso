<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\Totp;

use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Totp::class, 'verify')]
final class VerifyTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    private const NOW = 1111111111;

    public function testItAcceptsTheCurrentCodeAndReturnsItsStep(): void
    {
        // Arrange
        $step = intdiv(self::NOW, 30);

        // Act
        $matched = Totp::verify(self::SECRET, Totp::code(self::SECRET, $step), 0, self::NOW);

        // Assert
        $this->assertSame($step, $matched);
    }

    public function testItAcceptsTheStepsEitherSideForClockDrift(): void
    {
        // Arrange
        $step = intdiv(self::NOW, 30);

        // Act
        $before = Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 1), 0, self::NOW);
        $after = Totp::verify(self::SECRET, Totp::code(self::SECRET, $step + 1), 0, self::NOW);

        // Assert
        $this->assertSame([$step - 1, $step + 1], [$before, $after]);
    }

    public function testItRejectsAStepTwoAwayAndAWrongCode(): void
    {
        // Arrange
        $step = intdiv(self::NOW, 30);

        // Act
        $old = Totp::verify(self::SECRET, Totp::code(self::SECRET, $step - 2), 0, self::NOW);
        $wrong = Totp::verify(self::SECRET, '000000', 0, self::NOW);

        // Assert
        $this->assertNull($old);
        $this->assertNull($wrong);
    }

    public function testItRejectsACodeFromAStepThatWasAlreadyAccepted(): void
    {
        // Arrange
        $step = intdiv(self::NOW, 30);

        // Act
        $replayed = Totp::verify(self::SECRET, Totp::code(self::SECRET, $step), $step, self::NOW);

        // Assert
        $this->assertNull($replayed);
    }

    public function testItIgnoresSpacesAndRejectsInputThatIsNotSixDigits(): void
    {
        // Arrange
        $code = Totp::code(self::SECRET, intdiv(self::NOW, 30));
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

        // Act
        $withSpace = Totp::verify(self::SECRET, $spaced, 0, self::NOW);
        $letters = Totp::verify(self::SECRET, 'abcdef', 0, self::NOW);

        // Assert
        $this->assertNotNull($withSpace);
        $this->assertNull($letters);
    }
}
