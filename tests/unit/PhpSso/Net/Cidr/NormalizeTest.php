<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Net\Cidr;

use PhpSso\Net\Cidr;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Cidr::class, 'normalize')]
final class NormalizeTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function cases(): array
    {
        return [
            'ipv4 range is kept' => ['10.0.0.0/8', '10.0.0.0/8'],
            'surrounding whitespace is trimmed' => ['  10.0.0.1 ', '10.0.0.1'],
            'ipv6 is compressed' => ['2001:0db8::0001', '2001:db8::1'],
            'ipv6 loopback' => ['::1', '::1'],
            'prefix too long for ipv4' => ['1.2.3.4/33', null],
            'empty prefix' => ['1.2.3.4/', null],
            'non numeric prefix' => ['1.2.3.4/abc', null],
            'not an address' => ['x', null],
            'empty string' => ['', null],
        ];
    }

    #[DataProvider('cases')]
    public function testItReturnsTheCanonicalFormOrNull(string $input, ?string $expected): void
    {
        // Arrange: input comes from the data provider

        // Act
        $result = Cidr::normalize($input);

        // Assert
        $this->assertSame($expected, $result);
    }
}
