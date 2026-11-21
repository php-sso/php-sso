<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\Base32;

use PhpSso\Auth\Mfa\Base32;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Base32::class, 'encode')]
#[CoversMethod(Base32::class, 'decode')]
final class RoundTripTest extends TestCase
{
    public function testItEncodesTheRfcTestString(): void
    {
        // Arrange
        $bytes = '12345678901234567890';

        // Act
        $encoded = Base32::encode($bytes);

        // Assert
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $encoded);
    }

    public function testItDecodesIgnoringCaseSpacesDashesAndPadding(): void
    {
        // Arrange
        $text = 'gezd gnbv-GY3TQOJQ====';

        // Act
        $decoded = Base32::decode($text);

        // Assert
        $this->assertSame('1234567890', $decoded);
    }

    public function testItGetsBackWhatWasEncoded(): void
    {
        // Arrange
        $bytes = random_bytes(20);

        // Act
        $decoded = Base32::decode(Base32::encode($bytes));

        // Assert
        $this->assertSame($bytes, $decoded);
    }

    public function testItRejectsACharacterOutsideTheAlphabet(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        Base32::decode('ABC1');

        // Assert (the exception above)
    }
}
