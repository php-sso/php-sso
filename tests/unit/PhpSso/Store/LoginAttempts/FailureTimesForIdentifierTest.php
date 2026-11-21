<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LoginAttempts;

use PhpSso\Store\LoginAttempts;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginAttempts::class, 'failureTimesForIdentifier')]
final class FailureTimesForIdentifierTest extends TestCase
{
    private TestData $data;
    private LoginAttempts $attempts;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->attempts = new LoginAttempts($this->data->db);
    }

    public function testItCountsFailuresForTheIdentifierIgnoringCase(): void
    {
        // Arrange
        $this->attempts->record('Alice@Example.com', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice@example.com', null, false, '203.0.113.2', 'agent');

        // Act
        $count = count($this->attempts->failureTimesForIdentifier('ALICE@example.com', null, 0));

        // Assert
        $this->assertSame(2, $count);
    }

    public function testItCountsFailuresForTheSameAccountUnderAnotherIdentifier(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->attempts->record('alice', $sub, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice@example.com', $sub, false, '203.0.113.1', 'agent');

        // Act
        $count = count($this->attempts->failureTimesForIdentifier('alice', $sub, 0));

        // Assert
        $this->assertSame(2, $count);
    }

    public function testItIgnoresSuccessesBlockedAttemptsAndOtherPeople(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $bob = $this->data->user('bob');
        $this->attempts->record('alice', $alice, true, '203.0.113.1', 'agent');
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent', true);
        $this->attempts->record('bob', $bob, false, '203.0.113.1', 'agent');

        // Act
        $count = count($this->attempts->failureTimesForIdentifier('alice', $alice, 0));

        // Assert
        $this->assertSame(0, $count);
    }

    public function testItIgnoresFailuresBeforeTheGivenTime(): void
    {
        // Arrange
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');

        // Act
        $count = count($this->attempts->failureTimesForIdentifier('alice', null, time() + 60));

        // Assert
        $this->assertSame(0, $count);
    }
}
