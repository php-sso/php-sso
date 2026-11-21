<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Calls to a provider that answer with JSON. Anything but a 2xx answer with a JSON object is a failure. */
final class HttpJson
{
    public function __construct(
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws SocialLoginException
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->send($this->requests->createRequest('GET', $url), $headers);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     * @return array<string, mixed>
     * @throws SocialLoginException
     */
    public function postForm(string $url, array $form, array $headers = []): array
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream(http_build_query($form, '', '&', PHP_QUERY_RFC3986)));

        return $this->send($request, $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send(\Psr\Http\Message\RequestInterface $request, array $headers): array
    {
        $request = $request->withHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $host = $request->getUri()->getHost();

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SocialLoginException('We could not reach the provider. Try again in a moment.', "{$host}: {$e->getMessage()}");
        }

        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new SocialLoginException('The provider refused the request.', "{$host} answered {$status}");
        }

        return $data;
    }
}
