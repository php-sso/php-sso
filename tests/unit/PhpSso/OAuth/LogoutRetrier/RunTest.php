<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\LogoutRetrier;

use PhpSso\Audit\Audit;
use PhpSso\OAuth\LogoutRetrier;
use PhpSso\OAuthServer\Logout\LogoutDelivery;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\InMemoryAuditLog;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetrier::class, 'run')]
final class RunTest extends TestCase
{
    private const T0 = 1_000_000;

    private function queued(SqlLogoutRetries $queue, string $session = 'sess-1'): void
    {
        $queue->enqueue($session, 'sub-1', 'app-1', 'HTTP 500', self::T0 + LogoutRetrier::BACKOFF[0], self::T0);
    }

    private static function delivery(bool $ok): LogoutDelivery
    {
        return new LogoutDelivery('app-1', 'https://app.example.com/bcl', $ok, $ok ? 204 : 503, $ok ? null : 'The client answered with HTTP 503.');
    }

    public function testItSendsAFreshNotificationToTheAppAndForgetsItWhenItGetsThrough(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue);
        $sent = [];
        $retrier = new LogoutRetrier($queue, static function (string $client, string $session, string $sub) use (&$sent): array {
            $sent[] = [$client, $session, $sub];

            return [self::delivery(true)];
        });

        // Act
        $result = $retrier->run(10, self::T0 + 60);

        // Assert
        $this->assertSame([['app-1', 'sess-1', 'sub-1']], $sent);
        $this->assertSame(['delivered' => 1, 'retrying' => 0, 'gave_up' => 0, 'gone' => 0], $result);
        $this->assertSame(0, $queue->count());
    }

    public function testNothingIsTriedBeforeItsTime(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue);
        $calls = 0;
        $retrier = new LogoutRetrier($queue, static function () use (&$calls): array {
            $calls++;

            return [self::delivery(true)];
        });

        // Act
        $result = $retrier->run(10, self::T0 + 59);

        // Assert
        $this->assertSame(0, $calls);
        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'gave_up' => 0, 'gone' => 0], $result);
        $this->assertSame(1, $queue->count());
    }

    public function testEachFailureWaitsLongerAndTheLastOneGivesUpAndIsAudited(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue);
        $log = new InMemoryAuditLog();
        $retrier = new LogoutRetrier($queue, static fn (): array => [self::delivery(false)], new Audit($log));
        $now = self::T0 + LogoutRetrier::BACKOFF[0];
        $waits = [];

        // Act
        for ($try = 1; $try < LogoutRetrier::MAX_ATTEMPTS; $try++) {
            $result = $retrier->run(10, $now);
            if ($queue->count() === 0) {
                break;
            }
            $next = $now + LogoutRetrier::BACKOFF[$try];
            $waits[] = [$queue->due($next - 1, 10) === [], $queue->due($next, 10) !== []];
            $now = $next;
        }

        // Assert
        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'gave_up' => 1, 'gone' => 0], $result);
        $this->assertSame(0, $queue->count());
        $this->assertSame([[true, true], [true, true], [true, true], [true, true]], $waits, 'not due a second before the backoff, due at it');
        $this->assertSame('logout.undelivered', $log->entries[0]['action']);
        $this->assertSame('system', $log->entries[0]['actor']);
        $this->assertSame('app-1', $log->entries[0]['target']);
        $this->assertSame(LogoutRetrier::MAX_ATTEMPTS, $log->entries[0]['detail']['attempts']);
        $this->assertSame('sess-1', $log->entries[0]['detail']['session']);
    }

    public function testAnAppThatNoLongerHasALogoutAddressIsLetGo(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue);
        $retrier = new LogoutRetrier($queue, static fn (): array => []);

        // Act
        $result = $retrier->run(10, self::T0 + 60);

        // Assert
        $this->assertSame(1, $result['gone']);
        $this->assertSame(0, $queue->count());
    }

    public function testAnErrorWhileSendingCountsAsAFailureAndDoesNotStopTheRest(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue, 'sess-1');
        $this->queued($queue, 'sess-2');
        $retrier = new LogoutRetrier($queue, static function (string $client, string $session): array {
            if ($session === 'sess-1') {
                throw new \RuntimeException('boom');
            }

            return [self::delivery(true)];
        });

        // Act
        $result = $retrier->run(10, self::T0 + 60);

        // Assert
        $this->assertSame(1, $result['retrying']);
        $this->assertSame(1, $result['delivered']);
        $this->assertSame(1, $queue->count());
    }

    public function testItTriesNoMoreThanTheLimitAtATime(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        foreach (['a', 'b', 'c', 'd'] as $session) {
            $this->queued($queue, $session);
        }
        $retrier = new LogoutRetrier($queue, static fn (): array => [self::delivery(true)]);

        // Act
        $retrier->run(3, self::T0 + 60);

        // Assert
        $this->assertSame(1, $queue->count());
    }

    public function testTwoProcessesDoNotSendTheSameNotification(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $this->queued($queue);
        $calls = 0;
        $retrier = new LogoutRetrier($queue, static function () use (&$calls): array {
            $calls++;

            return [self::delivery(false)];
        });

        // Act
        $retrier->run(10, self::T0 + 60);
        $retrier->run(10, self::T0 + 60);

        // Assert
        $this->assertSame(1, $calls, 'the second run finds it rescheduled, not due');
    }
}
