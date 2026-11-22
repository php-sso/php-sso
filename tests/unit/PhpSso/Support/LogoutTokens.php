<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use Laminas\Diactoros\Request;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\RequestInterface;

/** The request the OAuth library sends to tell an app that a session ended (the token is unsigned here: only its claims are read). */
final class LogoutTokens
{
    /** @param string|list<string> $audience */
    public static function request(string|array $audience, string $sid, string $sub): RequestInterface
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $token = $encode(['alg' => 'RS256', 'typ' => 'logout+jwt']) . '.' . $encode(['iss' => 'https://sso.example.com', 'aud' => $audience, 'sub' => $sub, 'sid' => $sid]) . '.signature';
        $body = new Stream('php://memory', 'wb+');
        $body->write(http_build_query(['logout_token' => $token], '', '&', PHP_QUERY_RFC3986));
        $body->rewind();

        return (new Request('https://app.example.com/backchannel-logout', 'POST'))->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody($body);
    }
}
