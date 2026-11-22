<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP client the OAuth library sends back-channel logout notifications with, that notes every one that fails (the app could not be reached, or did
 * not answer 2xx) for {@see LogoutRetrier}. What the library gets back is exactly what the real client answered.
 */
final class RetryingLogoutClient implements ClientInterface
{
    public function __construct(private ClientInterface $client, private LogoutRetrier $retrier)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->retrier->undelivered($request, $e->getMessage());

            throw $e;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->retrier->undelivered($request, 'The app answered with HTTP ' . $response->getStatusCode() . '.');
        }

        return $response;
    }
}
