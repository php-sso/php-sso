<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LoginAttempts;

use PhpSso\Store\LoginAttempts;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlLoginAttempts;

#[CoversMethod(LoginAttempts::class, 'clearForAccount')]
#[CoversMethod(LoginAttempts::class, 'clearForIdentifier')]
#[CoversMethod(LoginAttempts::class, 'clearForIp')]
final class ClearTest extends TestCase
{
    private TestData $data;
    private LoginAttempts $attempts;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->attempts = new SqlLoginAttempts($this->data->db);
    }

    public function testClearingAnAccountStopsItsFailuresCountingButKeepsTheHistory(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $bob = $this->data->user('bob');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice@example.com', $alice, false, '203.0.113.1', 'agent');
        $this->attempts->record('bob', $bob, false, '203.0.113.1', 'agent');

        // Act
        $cleared = $this->attempts->clearForAccount($alice, ['alice', 'alice@example.com']);

        // Assert
        $this->assertSame(2, $cleared);
        $this->assertSame([], $this->attempts->failureTimesForIdentifier('alice', $alice, 0));
        $this->assertCount(1, $this->attempts->failureTimesForIdentifier('bob', $bob, 0));
        $this->assertCount(3, $this->attempts->recent(10));
    }

    public function testClearingAnAccountAlsoClearsFailuresTypedBeforeItExistedUnderItsName(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $this->attempts->record('Alice', null, false, '203.0.113.1', 'agent');

        // Act
        $cleared = $this->attempts->clearForAccount($alice, ['alice']);

        // Assert
        $this->assertSame(1, $cleared);
    }

    public function testClearingAnIdentifierIgnoresCaseAndLeavesOthers(): void
    {
        // Arrange
        $this->attempts->record('Ghost', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('other', null, false, '203.0.113.1', 'agent');

        // Act
        $cleared = $this->attempts->clearForIdentifier('ghost');

        // Assert
        $this->assertSame(1, $cleared);
        $this->assertCount(1, $this->attempts->failureTimesFromIp('203.0.113.1', 0));
    }

    public function testClearingAnAddressStopsOnlyItsFailuresCounting(): void
    {
        // Arrange
        $this->attempts->record('a', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('b', null, false, '203.0.113.2', 'agent');

        // Act
        $this->attempts->clearForIp('203.0.113.1');

        // Assert
        $this->assertSame([], $this->attempts->failureTimesFromIp('203.0.113.1', 0));
        $this->assertCount(1, $this->attempts->failureTimesFromIp('203.0.113.2', 0));
    }

    public function testThrottledRefusalsNeverCountAsFailures(): void
    {
        // Arrange
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent', throttled: true);

        // Act
        $times = $this->attempts->failureTimesFromIp('203.0.113.1', 0);

        // Assert
        $this->assertSame([], $times);
        $this->assertTrue($this->attempts->hasThrottledSince('203.0.113.1', 'someone', 0));
        $this->assertCount(1, $this->attempts->recentThrottled(10));
    }
}
