<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Qr;

use PhpSso\Qr\QrEncoder;
use PhpSso\Qr\QrMatrix;

/**
 * Reads a QR code back from its modules, checking what it passes on the way: both copies of the format information, the
 * error correction of every block, and the text. It does its own field arithmetic and its own walk over the modules, so it
 * does not share mistakes with the encoder.
 */
final class QrReader
{
    /** @return array{mask: int, text: string} */
    public static function read(QrMatrix $matrix): array
    {
        $size = $matrix->size;
        $version = intdiv($size - 17, 4);
        $mask = self::formatMask($matrix);

        $isFunction = QrEncoder::functionModules($version);
        $bits = [];
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            $upward = (($right + 1) & 2) === 0;
            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? $size - 1 - $step : $step;
                foreach ([$right, $right - 1] as $x) {
                    if (!$isFunction[$y][$x]) {
                        $bits[] = $matrix->dark($x, $y) !== self::maskApplies($mask, $x, $y) ? 1 : 0;
                    }
                }
            }
        }
        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            if (count($chunk) === 8) {
                $codewords[] = bindec(implode('', $chunk));
            }
        }

        $data = self::checkedData($codewords, $version);

        return ['mask' => $mask, 'text' => self::text($data, $version)];
    }

    private static function formatMask(QrMatrix $m): int
    {
        $size = $m->size;
        $first = 0;
        $second = 0;
        for ($i = 0; $i < 15; $i++) {
            [$x1, $y1] = match (true) {
                $i < 6 => [8, $i],
                $i === 6 => [8, 7],
                $i === 7 => [8, 8],
                $i === 8 => [7, 8],
                default => [14 - $i, 8],
            };
            [$x2, $y2] = $i < 8 ? [$size - 1 - $i, 8] : [8, $size - 15 + $i];
            $first |= ($m->dark($x1, $y1) ? 1 : 0) << $i;
            $second |= ($m->dark($x2, $y2) ? 1 : 0) << $i;
        }
        if ($first !== $second) {
            throw new \RuntimeException('The two copies of the format information differ.');
        }
        $word = $first ^ 0x5412;
        $remainder = $word;
        for ($i = 14; $i >= 10; $i--) {
            if ((($remainder >> $i) & 1) === 1) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }
        if ($remainder !== 0) {
            throw new \RuntimeException('The format information is not a valid code word.');
        }
        if (($word >> 13) !== 0) {
            throw new \RuntimeException('The error correction level is not M.');
        }

        return ($word >> 10) & 7;
    }

    private static function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    /**
     * @param list<int> $codewords
     * @return list<int> the data codewords, once every block's error correction checks out
     */
    private static function checkedData(array $codewords, int $version): array
    {
        [$ecLength, $groups] = QrEncoder::layout($version);
        $lengths = [];
        foreach ($groups as [$count, $data]) {
            for ($i = 0; $i < $count; $i++) {
                $lengths[] = $data;
            }
        }
        $blocks = array_fill(0, count($lengths), []);
        $position = 0;
        for ($i = 0; $i < max($lengths); $i++) {
            foreach ($lengths as $b => $length) {
                if ($i < $length) {
                    $blocks[$b][] = $codewords[$position++];
                }
            }
        }
        for ($i = 0; $i < $ecLength; $i++) {
            foreach ($lengths as $b => $unused) {
                $blocks[$b][] = $codewords[$position++];
            }
        }
        if ($position !== count($codewords)) {
            throw new \RuntimeException('The codewords do not fit the blocks of this version.');
        }

        $data = [];
        foreach ($blocks as $b => $block) {
            // Every block is a multiple of the generator polynomial: it has the value 0 at 2^0 .. 2^(ec-1).
            for ($root = 0; $root < $ecLength; $root++) {
                $alpha = self::power($root);
                $value = 0;
                foreach ($block as $coefficient) {
                    $value = self::multiply($value, $alpha) ^ $coefficient;
                }
                if ($value !== 0) {
                    throw new \RuntimeException("Block {$b} fails its error correction check.");
                }
            }
            array_push($data, ...array_slice($block, 0, $lengths[$b]));
        }

        return $data;
    }

    /** @param list<int> $data */
    private static function text(array $data, int $version): string
    {
        $bits = '';
        foreach ($data as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        if (substr($bits, 0, 4) !== '0100') {
            throw new \RuntimeException('Not byte mode.');
        }
        $countBits = $version < 10 ? 8 : 16;
        $length = (int) bindec(substr($bits, 4, $countBits));
        $text = '';
        for ($i = 0; $i < $length; $i++) {
            $text .= chr((int) bindec(substr($bits, 4 + $countBits + 8 * $i, 8)));
        }

        return $text;
    }

    private static function multiply(int $a, int $b): int
    {
        $product = 0;
        while ($b > 0) {
            if (($b & 1) === 1) {
                $product ^= $a;
            }
            $a <<= 1;
            if (($a & 0x100) !== 0) {
                $a ^= 0x11D;
            }
            $b >>= 1;
        }

        return $product;
    }

    private static function power(int $exponent): int
    {
        $value = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $value = self::multiply($value, 2);
        }

        return $value;
    }
}
