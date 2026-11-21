<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Qr\SvgRenderer;

use PhpSso\Qr\QrEncoder;
use PhpSso\Qr\QrMatrix;
use PhpSso\Qr\SvgRenderer;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SvgRenderer::class, 'render')]
final class RenderTest extends TestCase
{
    public function testItDrawsOnlyShapesWithAQuietZoneAndAWhiteBackground(): void
    {
        // Arrange
        $matrix = QrEncoder::encode('otpauth://totp/Acme%3Aalice?secret=ZLQ2YYFDUWDSEJFMVEYG37SOKFCND5IH');

        // Act
        $svg = SvgRenderer::render($matrix, 240);

        // Assert
        $total = $matrix->size + 8;
        self::assertStringStartsWith('<svg ', $svg);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringNotContainsString('ZLQ2', $svg);
        self::assertStringNotContainsString('<text', $svg);
        self::assertStringContainsString('viewBox="0 0 ' . $total . ' ' . $total . '"', $svg);
        self::assertStringContainsString('width="240" height="240"', $svg);
        self::assertStringContainsString('fill="#ffffff"', $svg);
    }

    public function testTheDarkRunsInThePathAddUpToTheDarkModules(): void
    {
        // Arrange
        $matrix = QrEncoder::encode('count the dark modules');
        $dark = 0;
        for ($y = 0; $y < $matrix->size; $y++) {
            for ($x = 0; $x < $matrix->size; $x++) {
                $dark += $matrix->dark($x, $y) ? 1 : 0;
            }
        }

        // Act
        $svg = SvgRenderer::render($matrix);

        // Assert
        preg_match('/ d="([^"]*)"/', $svg, $path);
        preg_match_all('/M\d+ \d+h(\d+)v1h-\1z/', $path[1], $runs);
        self::assertSame($dark, array_sum(array_map('intval', $runs[1])));
        self::assertSame(strlen($path[1]), strlen(implode('', $runs[0])));
    }

    public function testEachRunStartsAfterTheQuietZone(): void
    {
        // Arrange
        $matrix = new QrMatrix(2, [[true, false], [false, true]]);

        // Act
        $svg = SvgRenderer::render($matrix, 100);

        // Assert
        self::assertStringContainsString('d="M4 4h1v1h-1zM5 5h1v1h-1z"', $svg);
        self::assertStringContainsString('viewBox="0 0 10 10"', $svg);
    }
}
