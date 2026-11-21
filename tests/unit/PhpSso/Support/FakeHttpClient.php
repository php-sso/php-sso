<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use Laminas\Diactoros\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client that answers from a table of URL => [status, body], and remembers what was asked. */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param array<string, array{int, string|array<mixed>}> $routes the URL without its query string => status and body (arrays become JSON) */
    public function __construct(public array $routes = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $uri = $request->getUri();
        $url = $uri->getScheme() . '://' . $uri->getAuthority() . $uri->getPath();
        [$status, $body] = $this->routes[$url] ?? [404, 'not found'];

        $response = (new Response())->withStatus($status);
        $response->getBody()->write(is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);

        return $response;
    }

    /** @return array<string, string> the form fields of the last POST to this URL */
    public function lastForm(string $url): array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request->getMethod() === 'POST' && str_starts_with((string) $request->getUri(), $url)) {
                parse_str((string) $request->getBody(), $fields);

                return $fields;
            }
        }

        return [];
    }
}
