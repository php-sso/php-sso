<?php

declare(strict_types=1);

namespace PhpSso\Qr;

/** Draws a QR code as inline SVG: dark squares on a white background with a quiet zone, whatever the page theme. */
final class SvgRenderer
{
    /** The quiet zone the standard asks for, in modules. */
    private const QUIET_ZONE = 4;

    /**
     * The SVG holds only shapes (one path of runs of dark modules), never the text it encodes, and has no XML prolog so it can
     * sit inside HTML.
     *
     * @param int $pixels the width and height it is drawn at
     */
    public static function render(QrMatrix $matrix, int $pixels = 240): string
    {
        $total = $matrix->size + 2 * self::QUIET_ZONE;
        $path = '';
        for ($y = 0; $y < $matrix->size; $y++) {
            for ($x = 0; $x < $matrix->size; $x++) {
                if (!$matrix->dark($x, $y)) {
                    continue;
                }
                $start = $x;
                while ($x + 1 < $matrix->size && $matrix->dark($x + 1, $y)) {
                    $x++;
                }
                $length = $x - $start + 1;
                $path .= 'M' . ($start + self::QUIET_ZONE) . ' ' . ($y + self::QUIET_ZONE) . 'h' . $length . 'v1h-' . $length . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="' . $pixels . '" height="' . $pixels . '" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . $path . '"/>'
            . '</svg>';
    }
}
