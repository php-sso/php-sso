<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LogoutRetries;

use PhpSso\Store\LogoutRetries;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetries::class, 'delete')]
#[CoversMethod(LogoutRetries::class, 'count')]
final class DeleteTest extends TestCase
{
    public function testItRemovesOnlyThatEntry(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('s1', 'u', 'app', 'x', 1000, 0);
        $queue->enqueue('s2', 'u', 'app', 'x', 1000, 0);
        $first = $queue->due(1000, 10)[0];

        // Act
        $queue->delete($first['id']);

        // Assert
        $this->assertSame(1, $queue->count());
        $this->assertNotSame($first['session_id'], $queue->due(1000, 10)[0]['session_id']);
    }
}
