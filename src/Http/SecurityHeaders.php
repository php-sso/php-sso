<?php

declare(strict_types=1);

namespace PhpSso\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * The headers every response carries so that a browser does not do what an attacker would like: frame the pages, guess a content
 * type, run a script from somewhere else, or send the address of a page along to the next site. A header the response set itself is left alone.
 */
final class SecurityHeaders
{
    /**
     * Scripts and styles only from this server (the pages have no inline script; inline `style` attributes are allowed because
     * the admin panel sets colours with them), images also from `data:` (the QR code), and no framing of any page.
     * `form-action` is left out on purpose: a browser would then refuse the redirect to an app after the sign-in form.
     */
    public const DEFAULT_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    /**
     * @param string|null $csp the Content-Security-Policy to send instead of the default (a theme that loads fonts or images from another server needs one)
     * @param bool $https whether people reach the server over HTTPS, which is when browsers are told to insist on it
     */
    public function __construct(private ?string $csp, private bool $https)
    {
    }

    public function apply(ResponseInterface $response): ResponseInterface
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY', // for browsers that do not know frame-ancestors
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => $this->csp ?? self::DEFAULT_CSP,
            'Cache-Control' => 'no-store',
        ];
        if ($this->https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
