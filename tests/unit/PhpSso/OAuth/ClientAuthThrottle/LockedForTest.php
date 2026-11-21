<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\ClientAuthThrottle;

use PhpSso\Database;
use PhpSso\OAuth\ClientAuthThrottle;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(ClientAuthThrottle::class, 'lockedFor')]
final class LockedForTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const LIMITS = ['enabled' => true, 'per_client_ip' => 3, 'per_ip' => 5, 'window_seconds' => 600];

    public function testNothingStopsAnAddressWithoutFailures(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);

        // Act
        $wait = $throttle->lockedFor('web', '10.0.0.1', self::NOW);

        // Assert
        self::assertNull($wait);
    }

    public function testAnAddressIsLockedOutOfAClientAfterTooManyFailuresForIt(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);
        foreach ([0, 1, 2] as $second) {
            $throttle->recordFailure('web', '10.0.0.1', self::NOW + $second);
        }

        // Act
        $wait = $throttle->lockedFor('web', '10.0.0.1', self::NOW + 3);

        // Assert
        self::assertSame(597, $wait);
    }

    public function testAnotherAddressIsNotLockedOutOfTheSameClient(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);
        foreach ([0, 1, 2] as $second) {
            $throttle->recordFailure('web', '10.0.0.1', self::NOW + $second);
        }

        // Act
        $wait = $throttle->lockedFor('web', '10.0.0.2', self::NOW + 3);

        // Assert
        self::assertNull($wait);
    }

    public function testTheSameAddressMayStillUseAnotherClient(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);
        foreach ([0, 1, 2] as $second) {
            $throttle->recordFailure('web', '10.0.0.1', self::NOW + $second);
        }

        // Act
        $wait = $throttle->lockedFor('other', '10.0.0.1', self::NOW + 3);

        // Assert
        self::assertNull($wait);
    }

    public function testAnAddressTryingManyClientsIsLockedOutOfAll(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $client) {
            $throttle->recordFailure($client, '10.0.0.1', self::NOW + $i);
        }

        // Act
        $wait = $throttle->lockedFor('f', '10.0.0.1', self::NOW + 5);

        // Assert
        self::assertSame(595, $wait);
    }

    public function testFailuresStopCountingWhenTheyLeaveTheWindow(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), self::LIMITS);
        foreach ([0, 1, 2] as $second) {
            $throttle->recordFailure('web', '10.0.0.1', self::NOW + $second);
        }

        // Act
        $wait = $throttle->lockedFor('web', '10.0.0.1', self::NOW + 603);

        // Assert
        self::assertNull($wait);
    }

    public function testItNeverLocksWhenSwitchedOff(): void
    {
        // Arrange
        $throttle = new ClientAuthThrottle(new Database(':memory:'), ['enabled' => false] + self::LIMITS);
        foreach (range(0, 20) as $second) {
            $throttle->recordFailure('web', '10.0.0.1', self::NOW + $second);
        }

        // Act
        $wait = $throttle->lockedFor('web', '10.0.0.1', self::NOW + 21);

        // Assert
        self::assertNull($wait);
    }
}
