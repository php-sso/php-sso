<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\LogoutRetries;

use PhpSso\Store\LogoutRetries;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetries::class, 'reschedule')]
final class RescheduleTest extends TestCase
{
    public function testItCountsTheAttemptAndSetsWhenTheNextOneIsDue(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('s1', 'u', 'app', 'x', 1000, 0);
        $id = $queue->due(1000, 1)[0]['id'];

        // Act
        $queue->reschedule($id, 2, 4000, 'HTTP 503');

        // Assert
        $this->assertSame([], $queue->due(3999, 10));
        $this->assertSame(2, $queue->due(4000, 10)[0]['attempts']);
    }
}
