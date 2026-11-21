<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginThrottle;

use PhpSso\Auth\LoginThrottle;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Settings;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginThrottle::class, 'failing')]
#[CoversMethod(LoginThrottle::class, 'lockedCount')]
#[CoversMethod(LoginThrottle::class, 'accountStatus')]
#[CoversMethod(LoginThrottle::class, 'resetAccount')]
#[CoversMethod(LoginThrottle::class, 'recordLocked')]
final class FailingTest extends TestCase
{
    private TestData $data;
    private LoginAttempts $attempts;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->attempts = new LoginAttempts($this->data->db);
        $this->throttle = new LoginThrottle($this->attempts, new Settings($this->data->db), ['per_identifier' => 2, 'per_ip' => 3, 'window_seconds' => 600]);
    }

    public function testItListsAccountsIdentifiersAndAddressesWithTheirCounts(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');
        $this->attempts->record('Ghost', null, false, '203.0.113.2', 'agent');

        // Act
        $rows = $this->throttle->failing();

        // Assert
        $byKey = array_column($rows, null, 'type');
        $this->assertSame(['account', 2, 2, true], [$rows[0]['type'], $rows[0]['failures'], $rows[0]['limit'], $rows[0]['locked']]);
        $this->assertSame(1, $byKey['identifier']['failures']);
        $this->assertFalse($byKey['identifier']['locked']);
        $this->assertCount(4, $rows);
    }

    public function testItPutsLockedEntriesFirstAndCountsThem(): void
    {
        // Arrange
        $this->attempts->record('ghost', null, false, '203.0.113.2', 'agent');
        $this->attempts->record('target', null, false, '203.0.113.3', 'agent');
        $this->attempts->record('target', null, false, '203.0.113.3', 'agent');

        // Act
        $rows = $this->throttle->failing();

        // Assert
        $this->assertSame('target', $rows[0]['label']);
        $this->assertTrue($rows[0]['locked']);
        $this->assertSame(1, $this->throttle->lockedCount());
    }

    public function testAnAccountsStatusIsItsOwnRowOrNull(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $bob = $this->data->user('bob');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');

        // Act
        $status = $this->throttle->accountStatus($alice);

        // Assert
        $this->assertSame(1, $status['failures']);
        $this->assertNull($this->throttle->accountStatus($bob));
    }

    public function testResettingAnAccountRemovesItFromTheList(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice', $alice, false, '203.0.113.1', 'agent');

        // Act
        $this->throttle->resetAccount($alice, 'alice', 'alice@example.com');

        // Assert
        $this->assertSame([], $this->throttle->failing());
    }

    public function testARefusalIsRecordedOncePerAddressAndWindow(): void
    {
        // Arrange
        $this->throttle->recordLocked('alice', null, '203.0.113.1', 'agent');

        // Act
        $this->throttle->recordLocked('bob', null, '203.0.113.1', 'agent');
        $this->throttle->recordLocked('alice', null, '203.0.113.7', 'agent');

        // Assert
        $this->assertCount(1, $this->attempts->recentThrottled(10));
    }
}
