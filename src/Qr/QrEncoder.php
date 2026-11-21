<?php

declare(strict_types=1);

namespace PhpSso\Qr;

/**
 * Encodes text as a QR code (ISO/IEC 18004): byte mode, error correction level M, versions 1 to 40, the smallest version that
 * fits. Byte mode takes the text as it is (UTF-8 for anything beyond ASCII), which every scanner reads. This is what an
 * `otpauth://` address needs; it does not try numeric, alphanumeric or kanji modes, and it does not split text into segments.
 */
final class QrEncoder
{
    private const MIN_VERSION = 1;
    private const MAX_VERSION = 40;

    /** Level M: for every version, the error correction codewords of each block and the blocks as [count, data codewords]. */
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]], 11 => [30, [[1, 50], [4, 51]]], 12 => [22, [[6, 36], [2, 37]]],
        13 => [22, [[8, 37], [1, 38]]], 14 => [24, [[4, 40], [5, 41]]], 15 => [24, [[5, 41], [5, 42]]],
        16 => [28, [[7, 45], [3, 46]]], 17 => [28, [[10, 46], [1, 47]]], 18 => [26, [[9, 43], [4, 44]]],
        19 => [26, [[3, 44], [11, 45]]], 20 => [26, [[3, 41], [13, 42]]], 21 => [26, [[17, 42]]], 22 => [28, [[17, 46]]],
        23 => [28, [[4, 47], [14, 48]]], 24 => [28, [[6, 45], [14, 46]]], 25 => [28, [[8, 47], [13, 48]]],
        26 => [28, [[19, 46], [4, 47]]], 27 => [28, [[22, 45], [3, 46]]], 28 => [28, [[3, 45], [23, 46]]],
        29 => [28, [[21, 45], [7, 46]]], 30 => [28, [[19, 47], [10, 48]]], 31 => [28, [[2, 46], [29, 47]]],
        32 => [28, [[10, 46], [23, 47]]], 33 => [28, [[14, 46], [21, 47]]], 34 => [28, [[14, 46], [23, 47]]],
        35 => [28, [[12, 47], [26, 48]]], 36 => [28, [[6, 47], [34, 48]]], 37 => [28, [[29, 46], [14, 47]]],
        38 => [28, [[13, 46], [32, 47]]], 39 => [28, [[40, 47], [7, 48]]], 40 => [28, [[18, 47], [31, 48]]],
    ];

    /** @var array{0: list<int>, 1: array<int, int>}|null the powers of 2 and their logarithms in GF(256) */
    private static ?array $field = null;

    /**
     * @throws \LengthException when the text does not fit in the largest QR code (2331 bytes)
     */
    public static function encode(string $text): QrMatrix
    {
        $version = self::versionFor(strlen($text));
        $codewords = self::withErrorCorrection(self::dataCodewords($text, $version), $version);
        $size = 17 + 4 * $version;

        [$modules, $isFunction] = self::functionPatterns($version);
        self::placeCodewords($modules, $isFunction, $codewords, $size);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::masked($modules, $isFunction, $mask, $size);
            self::drawFormat($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $best = $candidate;
                $bestPenalty = $penalty;
            }
        }

        return new QrMatrix($size, $best ?? $modules);
    }

    /** The largest number of bytes a code of this version holds. */
    public static function capacity(int $version): int
    {
        return intdiv(self::dataCodewordCount($version) * 8 - 4 - self::countBits($version), 8);
    }

    /**
     * How a version's codewords are split: the error correction codewords per block, and the blocks as [count, data codewords].
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>}
     */
    public static function layout(int $version): array
    {
        return self::BLOCKS[$version];
    }

    /**
     * Which modules of a version are not data: finder, separator, timing and alignment patterns, format and version areas.
     *
     * @return list<list<bool>>
     */
    public static function functionModules(int $version): array
    {
        [, $isFunction] = self::functionPatterns($version);

        return $isFunction;
    }

    private static function versionFor(int $length): int
    {
        for ($version = self::MIN_VERSION; $version <= self::MAX_VERSION; $version++) {
            if ($length <= self::capacity($version)) {
                return $version;
            }
        }

        throw new \LengthException('That text is too long for a QR code (at most ' . self::capacity(self::MAX_VERSION) . ' bytes).');
    }

    private static function countBits(int $version): int
    {
        return $version < 10 ? 8 : 16;
    }

    private static function dataCodewordCount(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version][1] as [$count, $data]) {
            $total += $count * $data;
        }

        return $total;
    }

    /** @return list<int> */
    private static function dataCodewords(string $text, int $version): array
    {
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $append(0b0100, 4); // byte mode
        $append(strlen($text), self::countBits($version));
        foreach (str_split($text) as $char) {
            $append(ord($char), 8);
        }

        $capacity = self::dataCodewordCount($version) * 8;
        $append(0, min(4, $capacity - count($bits))); // terminator
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $bytes = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $byte = 0;
            foreach ($chunk as $bit) {
                $byte = ($byte << 1) | $bit;
            }
            $bytes[] = $byte;
        }

        return $bytes;
    }

    /**
     * Splits the data into blocks, adds each block's error correction codewords, and interleaves them as the standard says.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function withErrorCorrection(array $data, int $version): array
    {
        [$ecLength, $groups] = self::BLOCKS[$version];
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $length]) {
            for ($i = 0; $i < $count; $i++) {
                $blocks[] = array_slice($data, $offset, $length);
                $offset += $length;
            }
        }
        $generator = self::generator($ecLength);
        $corrections = array_map(static fn (array $block): array => self::remainder($block, $generator), $blocks);

        $result = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($corrections as $correction) {
                $result[] = $correction[$i];
            }
        }

        return $result;
    }

    /** @return array{0: list<int>, 1: array<int, int>} */
    private static function field(): array
    {
        if (self::$field === null) {
            $exp = [];
            $log = [];
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                $exp[$i] = $x;
                $log[$x] = $i;
                $x <<= 1;
                if (($x & 0x100) !== 0) {
                    $x ^= 0x11D;
                }
            }
            for ($i = 255; $i < 510; $i++) {
                $exp[$i] = $exp[$i - 255];
            }
            self::$field = [$exp, $log];
        }

        return self::$field;
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        [$exp, $log] = self::field();

        return $exp[$log[$a] + $log[$b]];
    }

    /** The product of (x - 2^i) for i below `$degree`; the first coefficient is the leading 1. @return list<int> */
    private static function generator(int $degree): array
    {
        [$exp] = self::field();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, $exp[$i]);
            }
            $poly = $next;
        }

        return $poly;
    }

    /**
     * @param list<int> $block
     * @param list<int> $generator
     * @return list<int> the error correction codewords of the block
     */
    private static function remainder(array $block, array $generator): array
    {
        $degree = count($generator) - 1;
        $rest = array_fill(0, $degree, 0);
        foreach ($block as $byte) {
            $factor = $byte ^ array_shift($rest);
            $rest[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $rest[$i] ^= self::multiply($generator[$i + 1], $factor);
            }
        }

        return $rest;
    }

    /** @return list<int> where the alignment patterns sit, in both directions */
    private static function alignmentPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }
        $count = intdiv($version, 7) + 2;
        $step = $version === 32 ? 26 : intdiv($version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $positions = [6];
        for ($position = 17 + 4 * $version - 7; count($positions) < $count; $position -= $step) {
            array_splice($positions, 1, 0, [$position]);
        }

        return $positions;
    }

    /**
     * @return array{0: list<list<bool>>, 1: list<list<bool>>} the modules of the fixed patterns, and which modules they are
     */
    private static function functionPatterns(int $version): array
    {
        $size = 17 + 4 * $version;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = $modules;
        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$isFunction, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $modules[$y][$x] = $dark;
                $isFunction[$y][$x] = true;
            }
        };

        for ($i = 0; $i < $size; $i++) { // timing patterns
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) { // finder patterns with their separators
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $distance = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $distance !== 2 && $distance !== 4);
                }
            }
        }
        $positions = self::alignmentPositions($version);
        $last = count($positions) - 1;
        foreach ($positions as $i => $cy) {
            foreach ($positions as $j => $cx) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue; // the finder patterns are there
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        foreach (self::formatCoordinates($size) as $copy) { // reserved now, drawn once the mask is chosen
            foreach ($copy as [$x, $y]) {
                $isFunction[$y][$x] = true;
            }
        }
        if ($version >= 7) {
            $remainder = $version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $set($a, $b, $dark);
                $set($b, $a, $dark);
            }
        }
        $set(8, $size - 8, true); // the dark module

        return [$modules, $isFunction];
    }

    /**
     * Puts the codewords in the free modules, two columns at a time from the bottom right, zig-zagging up and down. Modules
     * left over after the last codeword (the remainder bits) stay light.
     *
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @param list<int> $codewords
     */
    private static function placeCodewords(array &$modules, array $isFunction, array $codewords, int $size): void
    {
        $total = count($codewords) * 8;
        $i = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // the vertical timing pattern is not part of any column pair
            }
            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $size - 1 - $vertical : $vertical;
                    if (!$isFunction[$y][$x] && $i < $total) {
                        $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @return list<list<bool>> a copy with the mask applied to the data modules
     */
    private static function masked(array $modules, array $isFunction, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$isFunction[$y][$x] && self::maskApplies($mask, $x, $y)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    private static function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => $x * $y % 2 + $x * $y % 3 === 0,
            6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
            default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
        };
    }

    /** @return list<list<array{0: int, 1: int}>> the [x, y] of format bits 0 to 14, for the two copies */
    private static function formatCoordinates(int $size): array
    {
        $first = [];
        $second = [];
        for ($i = 0; $i < 15; $i++) {
            $first[] = match (true) {
                $i < 6 => [8, $i],
                $i === 6 => [8, 7],
                $i === 7 => [8, 8],
                $i === 8 => [7, 8],
                default => [14 - $i, 8],
            };
            $second[] = $i < 8 ? [$size - 1 - $i, 8] : [8, $size - 15 + $i];
        }

        return [$first, $second];
    }

    /**
     * Writes the format information: the error correction level (M) and the mask, protected by a BCH code.
     *
     * @param list<list<bool>> $modules
     */
    private static function drawFormat(array &$modules, int $mask, int $size): void
    {
        $data = (0b00 << 3) | $mask; // level M is 00
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;
        foreach (self::formatCoordinates($size) as $copy) {
            foreach ($copy as $i => [$x, $y]) {
                $modules[$y][$x] = (($bits >> $i) & 1) === 1;
            }
        }
    }

    /**
     * How hard the pattern is for a scanner (the standard's four rules): runs of one colour, 2 by 2 blocks, finder-like
     * patterns, and an unbalanced share of dark modules. The mask with the lowest score wins.
     *
     * @param list<list<bool>> $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $penalty = 0;
        $lines = [];
        for ($i = 0; $i < $size; $i++) {
            $lines[] = $modules[$i];
            $lines[] = array_column($modules, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i < $size; $i++) {
                if ($line[$i] === $line[$i - 1]) {
                    $run++;

                    continue;
                }
                $penalty += $run >= 5 ? 3 + $run - 5 : 0;
                $run = 1;
            }
            $penalty += $run >= 5 ? 3 + $run - 5 : 0;

            $text = implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $line));
            $penalty += 40 * (preg_match_all('/(?=10111010000)/', $text) + preg_match_all('/(?=00001011101)/', $text));
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($modules[$y][$x] === $modules[$y][$x + 1] && $modules[$y][$x] === $modules[$y + 1][$x] && $modules[$y][$x] === $modules[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }
        $dark = 0;
        foreach ($modules as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $penalty += 10 * max(0, intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1);

        return $penalty;
    }
}
