<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\LogoutRetrier;

use Laminas\Diactoros\Request;
use PhpSso\OAuth\LogoutRetrier;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\LogoutTokens;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetrier::class, 'undelivered')]
final class UndeliveredTest extends TestCase
{
    private function retrier(SqlLogoutRetries $queue): LogoutRetrier
    {
        return new LogoutRetrier($queue, static fn (): array => []);
    }

    public function testItQueuesTheAppTheSessionAndThePersonFromTheTokenInTheRequest(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());

        // Act
        $this->retrier($queue)->undelivered(LogoutTokens::request('app-1', 'sess-1', 'sub-1'), 'HTTP 500', 1000);

        // Assert
        $job = $queue->due(1000 + LogoutRetrier::BACKOFF[0], 10)[0];
        $this->assertSame(['sess-1', 'sub-1', 'app-1', 1], [$job['session_id'], $job['user_sub'], $job['client_id'], $job['attempts']]);
        $this->assertSame([], $queue->due(1000 + LogoutRetrier::BACKOFF[0] - 1, 10));
    }

    public function testAnAudienceThatIsAListNamesTheFirstApp(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());

        // Act
        $this->retrier($queue)->undelivered(LogoutTokens::request(['app-1', 'app-2'], 'sess-1', 'sub-1'), 'x', 1000);

        // Assert
        $this->assertSame('app-1', $queue->due(99999, 10)[0]['client_id']);
    }

    public function testTheRequestCanStillBeReadAfterwards(): void
    {
        // Arrange
        $request = LogoutTokens::request('app-1', 'sess-1', 'sub-1');
        $before = (string) $request->getBody();

        // Act
        (new LogoutRetrier(new SqlLogoutRetries(TestConnection::open()), static fn (): array => []))->undelivered($request, 'x', 1000);

        // Assert
        $this->assertSame($before, (string) $request->getBody());
    }

    public function testARequestThatIsNotALogoutTokenIsIgnored(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $other = (new Request('https://app.example.com/other', 'POST'));
        $garbage = LogoutTokens::request('app-1', 'sess-1', 'sub-1')->withBody((function () {
            $body = new \Laminas\Diactoros\Stream('php://memory', 'wb+');
            $body->write('logout_token=not.a.jwt');
            $body->rewind();

            return $body;
        })());

        // Act
        $this->retrier($queue)->undelivered($other, 'x', 1000);
        $this->retrier($queue)->undelivered($garbage, 'x', 1000);

        // Assert
        $this->assertSame(0, $queue->count());
    }
}
