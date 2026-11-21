<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Qr\QrEncoder;

use PhpSso\Qr\QrEncoder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(QrEncoder::class, 'capacity')]
final class CapacityTest extends TestCase
{
    /** @return iterable<string, array{int, int}> the bytes a version holds at level M, from the standard's capacity table */
    public static function known(): iterable
    {
        yield 'version 1' => [1, 14];
        yield 'version 2' => [2, 26];
        yield 'version 3' => [3, 42];
        yield 'version 9' => [9, 180];
        yield 'version 10 (the count field grows)' => [10, 213];
        yield 'version 20' => [20, 666];
        yield 'version 40' => [40, 2331];
    }

    #[DataProvider('known')]
    public function testItMatchesTheStandardsTable(int $version, int $bytes): void
    {
        // Arrange
        // (the version and the expected capacity come from the data set)

        // Act
        $capacity = QrEncoder::capacity($version);

        // Assert
        self::assertSame($bytes, $capacity);
    }
}
