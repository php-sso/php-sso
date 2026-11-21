<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

use PhpSso\Qr\QrEncoder;
use PhpSso\Qr\SvgRenderer;

/** Draws a QR code on the server, as inline SVG, so a secret never goes to an outside QR service. */
final class QrCode
{
    /**
     * Dark squares on a white background with a quiet zone, whatever the page theme (scanners need that).
     * The SVG holds only shapes, never the text it encodes, and has no XML prolog so it can sit inside HTML.
     *
     * @throws \LengthException when the text is too long for a QR code
     */
    public static function svg(string $text): string
    {
        return SvgRenderer::render(QrEncoder::encode($text));
    }
}
