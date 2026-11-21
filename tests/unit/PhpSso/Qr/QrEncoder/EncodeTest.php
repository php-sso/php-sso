<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Qr\QrEncoder;

use PhpSso\Qr\QrEncoder;
use PhpSso\Qr\QrMatrix;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Qr\QrReader;

#[CoversMethod(QrEncoder::class, 'encode')]
final class EncodeTest extends TestCase
{
    /** @return list<string> */
    private function rows(QrMatrix $matrix): array
    {
        $rows = [];
        for ($y = 0; $y < $matrix->size; $y++) {
            $row = '';
            for ($x = 0; $x < $matrix->size; $x++) {
                $row .= $matrix->dark($x, $y) ? '1' : '0';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function testItGivesTheCodeAnIndependentDecoderReadBack(): void
    {
        // Arrange
        $text = 'A';
        $expected = [
            '111111101001001111111', '100000101111101000001', '101110100010101011101', '101110101101101011101', '101110100111001011101',
            '100000100101101000001', '111111101010101111111', '000000001101100000000', '101101110011101001011', '010110010101111001000',
            '110011101101000001101', '101101000001001111100', '100111100100100100100', '000000001011001001001', '111111101001100101000',
            '100000101100000110110', '101110100110111110001', '101110101111001111110', '101110101010101100000', '100000100010010100101',
            '111111101000010010000',
        ];

        // Act
        $matrix = QrEncoder::encode($text);

        // Assert (this matrix was decoded to "A" by OpenCV's QR reader)
        self::assertSame($expected, $this->rows($matrix));
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'a single character' => ['A'];
        yield 'nothing but a space' => [' '];
        yield 'a typical otpauth address (one error correction block per few)' => ['otpauth://totp/Acme%20SSO%3Aalice?secret=ZLQ2YYFDUWDSEJFMVEYG37SOKFCND5IH&issuer=Acme%20SSO&algorithm=SHA1&digits=6&period=30'];
        yield 'text beyond ASCII, as UTF-8' => ["Caf\u{e9} \u{1F510}"];
        yield 'two blocks of different length (version 8)' => [str_repeat('x', 140)];
        yield 'a version with version information (10)' => [str_repeat('y', 200)];
        yield 'many blocks (version 25)' => [str_repeat('z', 900)];
        yield 'the largest (version 40)' => [str_repeat('q', 2331)];
    }

    #[DataProvider('texts')]
    public function testTheCodeHoldsTheTextAndEveryChecksumHolds(string $text): void
    {
        // Arrange
        $matrix = QrEncoder::encode($text);

        // Act
        $read = QrReader::read($matrix);

        // Assert
        self::assertSame($text, $read['text']);
    }

    public function testTheSameTextAlwaysGivesTheSameCode(): void
    {
        // Arrange
        $text = 'otpauth://totp/a?secret=AAAAAAAAAAAAAAAA';

        // Act
        $one = $this->rows(QrEncoder::encode($text));
        $two = $this->rows(QrEncoder::encode($text));

        // Assert
        self::assertSame($one, $two);
    }

    public function testTheFinderTimingAndDarkModulesAreWhereTheyShouldBe(): void
    {
        // Arrange
        $matrix = QrEncoder::encode('timing');
        $size = $matrix->size;

        // Act
        $corners = [$matrix->dark(0, 0), $matrix->dark($size - 1, 0), $matrix->dark(0, $size - 1), $matrix->dark($size - 1, $size - 1)];
        $centresOfTheFinders = [$matrix->dark(3, 3), $matrix->dark($size - 4, 3), $matrix->dark(3, $size - 4)];
        $timing = [];
        for ($i = 8; $i < $size - 8; $i++) {
            $timing[] = [$matrix->dark($i, 6), $matrix->dark(6, $i)];
        }

        // Assert
        self::assertSame([true, true, true], array_slice($corners, 0, 3));
        self::assertSame([true, true, true], $centresOfTheFinders);
        self::assertTrue($matrix->dark(8, $size - 8));
        foreach ($timing as $i => [$horizontal, $vertical]) {
            self::assertSame($i % 2 === 0, $horizontal);
            self::assertSame($i % 2 === 0, $vertical);
        }
    }

    public function testTheSmallestCodeThatFitsIsChosen(): void
    {
        // Arrange
        $fits = str_repeat('a', 14);
        $overflows = str_repeat('a', 15);

        // Act
        $small = QrEncoder::encode($fits);
        $bigger = QrEncoder::encode($overflows);

        // Assert
        self::assertSame([21, 25], [$small->size, $bigger->size]);
    }

    public function testTextTooLongForAnyCodeIsRefused(): void
    {
        // Arrange
        $text = str_repeat('a', QrEncoder::capacity(40) + 1);

        // Act
        $encode = fn () => QrEncoder::encode($text);

        // Assert
        $this->expectException(\LengthException::class);
        $encode();
    }
}
