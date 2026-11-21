<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Net\Cidr;

use PhpSso\Net\Cidr;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Cidr::class, 'contains')]
final class ContainsTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'ipv4 inside range' => ['10.0.0.0/8', '10.1.2.3', true],
            'ipv4 outside range' => ['10.0.0.0/8', '11.0.0.1', false],
            'ipv4 last address of range' => ['192.168.1.0/25', '192.168.1.127', true],
            'ipv4 first address after range' => ['192.168.1.0/25', '192.168.1.128', false],
            'single address matches itself' => ['203.0.113.7', '203.0.113.7', true],
            'single address rejects neighbour' => ['203.0.113.7', '203.0.113.8', false],
            'zero prefix matches everything' => ['0.0.0.0/0', '8.8.8.8', true],
            'ipv6 inside range' => ['2001:db8::/32', '2001:db8:ffff::1', true],
            'ipv6 outside range' => ['2001:db8::/32', '2001:db9::1', false],
            'ipv4 range never matches ipv6' => ['10.0.0.0/8', '2001:db8::1', false],
            'ipv4-mapped ipv6 counts as ipv4' => ['10.0.0.0/8', '::ffff:10.1.1.1', true],
            'garbage address' => ['10.0.0.0/8', 'garbage', false],
            'garbage range' => ['garbage', '10.0.0.1', false],
        ];
    }

    #[DataProvider('cases')]
    public function testItMatchesAddressesAgainstRanges(string $range, string $ip, bool $expected): void
    {
        // Arrange: range and address come from the data provider

        // Act
        $result = Cidr::contains($range, $ip);

        // Assert
        $this->assertSame($expected, $result);
    }
}
