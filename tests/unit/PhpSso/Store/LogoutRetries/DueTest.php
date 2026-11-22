<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LogoutRetries;

use PhpSso\Store\LogoutRetries;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetries::class, 'due')]
final class DueTest extends TestCase
{
    public function testItListsOnlyWhatIsDueOldestFirstAndStopsAtTheLimit(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('s3', 'u', 'app', 'x', 3000, 0);
        $queue->enqueue('s1', 'u', 'app', 'x', 1000, 0);
        $queue->enqueue('s2', 'u', 'app', 'x', 2000, 0);
        $queue->enqueue('later', 'u', 'app', 'x', 9000, 0);

        // Act
        $all = $queue->due(3000, 10);
        $two = $queue->due(3000, 2);

        // Assert
        $this->assertSame(['s1', 's2', 's3'], array_column($all, 'session_id'));
        $this->assertSame(['s1', 's2'], array_column($two, 'session_id'));
    }
}
