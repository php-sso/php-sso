<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RetryingLogoutClient;

use Laminas\Diactoros\Response;
use PhpSso\OAuth\LogoutRetrier;
use PhpSso\OAuth\RetryingLogoutClient;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\unit\PhpSso\Support\LogoutTokens;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(RetryingLogoutClient::class, 'sendRequest')]
final class SendRequestTest extends TestCase
{
    private function client(int|\Throwable $answer): ClientInterface
    {
        return new class($answer) implements ClientInterface {
            public function __construct(private int|\Throwable $answer)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return new Response('php://memory', $this->answer);
            }
        };
    }

    public function testAnAppThatAcceptsItIsLeftAlone(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $client = new RetryingLogoutClient($this->client(204), new LogoutRetrier($queue, static fn (): array => []));

        // Act
        $response = $client->sendRequest(LogoutTokens::request('app-1', 'sess-1', 'sub-1'));

        // Assert
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, $queue->count());
    }

    public function testAnAppThatAnswersWithAnErrorIsQueuedAndTheLibraryStillSeesTheAnswer(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $client = new RetryingLogoutClient($this->client(503), new LogoutRetrier($queue, static fn (): array => []));

        // Act
        $response = $client->sendRequest(LogoutTokens::request('app-1', 'sess-1', 'sub-1'));

        // Assert
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(1, $queue->count());
    }

    public function testAnAppThatCannotBeReachedIsQueuedAndTheErrorIsPassedOn(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $down = new class('connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };
        $client = new RetryingLogoutClient($this->client($down), new LogoutRetrier($queue, static fn (): array => []));
        $this->expectExceptionObject($down);

        // Act
        try {
            $client->sendRequest(LogoutTokens::request('app-1', 'sess-1', 'sub-1'));
        } finally {
            // Assert (also: it was queued)
            $this->assertSame(1, $queue->count());
        }
    }
}
