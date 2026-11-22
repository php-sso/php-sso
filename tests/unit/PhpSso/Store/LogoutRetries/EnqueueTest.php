<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LogoutRetries;

use PhpSso\Store\LogoutRetries;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetries::class, 'enqueue')]
final class EnqueueTest extends TestCase
{
    public function testItKeepsWhichAppHasToBeToldAboutWhichSessionAndWhen(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());

        // Act
        $queue->enqueue('sess-1', 'sub-1', 'app-1', 'HTTP 500', 1060, 1000);

        // Assert
        $this->assertSame([['id' => $queue->due(2000, 10)[0]['id'], 'session_id' => 'sess-1', 'user_sub' => 'sub-1', 'client_id' => 'app-1', 'attempts' => 1]], $queue->due(2000, 10));
        $this->assertSame([], $queue->due(1059, 10));
    }

    public function testTheSameSessionAndAppIsQueuedOnlyOnce(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('sess-1', 'sub-1', 'app-1', 'first', 1060, 1000);

        // Act
        $queue->enqueue('sess-1', 'sub-1', 'app-1', 'second', 5000, 1001);

        // Assert
        $this->assertSame(1, $queue->count());
        $this->assertCount(1, $queue->due(1060, 10));
    }

    public function testAnotherAppOrSessionIsItsOwnEntry(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());

        // Act
        $queue->enqueue('sess-1', 'sub-1', 'app-1', 'x', 1060, 1000);
        $queue->enqueue('sess-1', 'sub-1', 'app-2', 'x', 1060, 1000);
        $queue->enqueue('sess-2', 'sub-1', 'app-1', 'x', 1060, 1000);

        // Assert
        $this->assertSame(3, $queue->count());
    }
}
