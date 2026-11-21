<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Net\StreamHttpClient;

use PhpSso\Net\StreamHttpClient;
use Laminas\Diactoros\Request;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

#[CoversMethod(StreamHttpClient::class, 'sendRequest')]
final class SendRequestTest extends TestCase
{
    private static mixed $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(20000, 40000);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }
        self::fail('The test server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    private function get(string $query): Request
    {
        return new Request('http://127.0.0.1:' . self::$port . '/?' . $query, 'GET');
    }

    public function testASmallAnswerIsReturnedWithItsStatus(): void
    {
        // Arrange
        $client = new StreamHttpClient(3.0, 1000);

        // Act
        $response = $client->sendRequest($this->get('bytes=500&status=200'));

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(500, strlen((string) $response->getBody()));
    }

    public function testAnErrorStatusIsReturnedNotThrown(): void
    {
        // Arrange
        $client = new StreamHttpClient(3.0, 1000);

        // Act
        $response = $client->sendRequest($this->get('bytes=10&status=404'));

        // Assert
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnAnswerBiggerThanTheLimitIsCutOffAndRefused(): void
    {
        // Arrange
        $client = new StreamHttpClient(3.0, 1000);

        // Act
        $send = fn () => $client->sendRequest($this->get('bytes=5000000'));

        // Assert
        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('larger than 1000 bytes');
        $send();
    }

    public function testAnAnswerAtExactlyTheLimitIsAccepted(): void
    {
        // Arrange
        $client = new StreamHttpClient(3.0, 1000);

        // Act
        $response = $client->sendRequest($this->get('bytes=1000'));

        // Assert
        $this->assertSame(1000, strlen((string) $response->getBody()));
    }

    public function testAnAnswerThatDribblesInForeverIsGivenUpOnInTime(): void
    {
        // Arrange
        $client = new StreamHttpClient(1.0, 1000000);
        $started = microtime(true);

        // Act
        $send = fn () => $client->sendRequest($this->get('delay=0.4'));

        // Assert
        try {
            $send();
            $this->fail('A never-ending answer was waited for.');
        } catch (ClientExceptionInterface $e) {
            $this->assertStringContainsString('took too long', $e->getMessage());
        }
        $this->assertLessThan(5.0, microtime(true) - $started);
    }

    public function testOnlyHttpAddressesAreReached(): void
    {
        // Arrange
        $client = new StreamHttpClient();
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getScheme')->willReturn('file');
        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        // Act
        $send = fn () => $client->sendRequest($request);

        // Assert
        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('Only http(s)');
        $send();
    }
}
