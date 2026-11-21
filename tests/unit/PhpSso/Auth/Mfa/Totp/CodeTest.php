<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\Totp;

use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Totp::class, 'code')]
final class CodeTest extends TestCase
{
    /** The secret and the values of RFC 6238 appendix B (SHA-1), cut to six digits. @return array<string, array{int, string}> */
    public static function vectors(): array
    {
        return [
            'time 59' => [59, '287082'],
            'time 1111111109' => [1111111109, '081804'],
            'time 1111111111' => [1111111111, '050471'],
            'time 1234567890' => [1234567890, '005924'],
            'time 2000000000' => [2000000000, '279037'],
            'time 20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('vectors')]
    public function testItMatchesTheRfcTestVectors(int $time, string $expected): void
    {
        // Arrange
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // Act
        $code = Totp::code($secret, intdiv($time, 30));

        // Assert
        $this->assertSame($expected, $code);
    }
}
