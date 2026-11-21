<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Qr\QrEncoder;

use PhpSso\Qr\QrEncoder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(QrEncoder::class, 'layout')]
final class LayoutTest extends TestCase
{
    /** The standard's number of data codewords at level M, for versions 1 to 40. */
    private const DATA = [16, 28, 44, 64, 86, 108, 124, 154, 182, 216, 254, 290, 334, 365, 415, 453, 507, 563, 627, 669, 714, 782, 860, 914, 1000, 1062, 1128, 1193, 1267, 1373, 1455, 1541, 1631, 1725, 1812, 1914, 1992, 2102, 2216, 2334];

    /** The standard's total number of codewords (data and error correction), for versions 1 to 40. */
    private const TOTAL = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346, 404, 466, 532, 581, 655, 733, 815, 901, 991, 1085, 1156, 1258, 1364, 1474, 1588, 1706, 1828, 1921, 2051, 2185, 2323, 2465, 2611, 2761, 2876, 3034, 3196, 3362, 3532, 3706];

    public function testEveryVersionsBlocksAddUpToTheStandardsCodewordCounts(): void
    {
        // Arrange
        $found = [];

        // Act
        for ($version = 1; $version <= 40; $version++) {
            [$ecLength, $groups] = QrEncoder::layout($version);
            $blocks = 0;
            $data = 0;
            foreach ($groups as [$count, $length]) {
                $blocks += $count;
                $data += $count * $length;
            }
            $found[$version] = [$data, $data + $blocks * $ecLength];
        }

        // Assert
        $expected = [];
        for ($version = 1; $version <= 40; $version++) {
            $expected[$version] = [self::DATA[$version - 1], self::TOTAL[$version - 1]];
        }
        self::assertSame($expected, $found);
    }
}
