<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LogoutRetries;

use PhpSso\Store\LogoutRetries;
use PhpSso\Store\Sql\SqlLogoutRetries;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(LogoutRetries::class, 'claim')]
final class ClaimTest extends TestCase
{
    public function testOnlyTheFirstProcessGetsAnEntryAndItIsNotDueWhileItIsTaken(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('s1', 'u', 'app', 'x', 1000, 0);
        $id = $queue->due(1000, 1)[0]['id'];

        // Act
        $first = $queue->claim($id, 1000, 1300);
        $second = $queue->claim($id, 1000, 1300);

        // Assert
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame([], $queue->due(1299, 10));
        $this->assertCount(1, $queue->due(1300, 10), 'a process that died halfway does not lose it');
    }

    public function testAnEntryThatIsNotDueYetCannotBeTaken(): void
    {
        // Arrange
        $queue = new SqlLogoutRetries(TestConnection::open());
        $queue->enqueue('s1', 'u', 'app', 'x', 5000, 0);
        $id = $queue->due(5000, 1)[0]['id'];

        // Act
        $claimed = $queue->claim($id, 1000, 1300);

        // Assert
        $this->assertFalse($claimed);
    }
}
