<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\AccessTester;

use PhpSso\AccessTester;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AccessTester::class, 'validIp')]
final class ValidIpTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function cases(): array
    {
        return [
            'ipv4' => ['203.0.113.7', true],
            'ipv6' => ['2001:db8::1', true],
            'a range is not an address' => ['10.0.0.0/8', false],
            'text' => ['hello', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('cases')]
    public function testItAcceptsOnlySingleAddresses(string $input, bool $expected): void
    {
        // Arrange: input comes from the data provider

        // Act
        $result = AccessTester::validIp($input);

        // Assert
        $this->assertSame($expected, $result);
    }
}
