<?php

declare(strict_types=1);

namespace PhpSso\Net;

use Laminas\Diactoros\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A minimal PSR-18 client on PHP streams, for the server-to-server calls the OAuth server and the social sign-in make (back-channel
 * logout, provider discovery and keys). Short timeout, no redirects, only http(s) URLs. The answer is read in pieces and cut
 * off when it is bigger than `$maxBytes` or takes longer than twice the timeout in all, so one slow or endless answer cannot
 * hold a worker or fill its memory.
 */
final readonly class StreamHttpClient implements ClientInterface
{
    public function __construct(private float $timeoutSeconds = 3.0, private int $maxBytes = 1048576)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $scheme = $request->getUri()->getScheme();
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new class('Only http(s) URLs can be requested.') extends RuntimeException implements ClientExceptionInterface {
            };
        }

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ': ' . implode(', ', $values);
        }

        $context = stream_context_create(['http' => [
            'method' => $request->getMethod(),
            'header' => implode("\r\n", $headers),
            'content' => (string) $request->getBody(),
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $stream = @fopen((string) $request->getUri(), 'rb', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($stream === false || preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $match) !== 1) {
            throw new class('The request to ' . $request->getUri()->getHost() . ' failed.') extends RuntimeException implements ClientExceptionInterface {
            };
        }
        $body = $this->read($stream, $request->getUri()->getHost());

        $response = (new Response())->withStatus((int) $match[1]);
        $response->getBody()->write($body);

        return $response;
    }

    /** @param resource $stream */
    private function read($stream, string $host): string
    {
        stream_set_timeout($stream, max(1, (int) ceil($this->timeoutSeconds)));
        $deadline = microtime(true) + $this->timeoutSeconds * 2;
        $body = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || stream_get_meta_data($stream)['timed_out']) {
                    throw new class('The answer from ' . $host . ' took too long.') extends RuntimeException implements ClientExceptionInterface {
                    };
                }
                $body .= $chunk;
                if (strlen($body) > $this->maxBytes) {
                    throw new class('The answer from ' . $host . ' is larger than ' . $this->maxBytes . ' bytes.') extends RuntimeException implements ClientExceptionInterface {
                    };
                }
                if (microtime(true) > $deadline) {
                    throw new class('The answer from ' . $host . ' took too long.') extends RuntimeException implements ClientExceptionInterface {
                    };
                }
            }
        } finally {
            fclose($stream);
        }

        return $body;
    }
}
