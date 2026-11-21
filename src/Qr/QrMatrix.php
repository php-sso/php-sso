<?php

declare(strict_types=1);

namespace PhpSso\Qr;

/** A finished QR code: a square grid of dark and light modules, without the quiet zone around it. */
final class QrMatrix
{
    /** @param list<list<bool>> $rows `$rows[$y][$x]` is true for a dark module */
    public function __construct(public readonly int $size, private readonly array $rows)
    {
    }

    public function dark(int $x, int $y): bool
    {
        return $this->rows[$y][$x] ?? false;
    }
}
