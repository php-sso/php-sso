<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginThrottle;

use PhpSso\Auth\LoginThrottle;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Settings;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginThrottle::class, 'lockedFor')]
final class LockedForTest extends TestCase
{
    private LoginAttempts $attempts;
    private Settings $settings;

    protected function setUp(): void
    {
        $data = new TestData();
        $this->attempts = new LoginAttempts($data->db);
        $this->settings = new Settings($data->db);
    }

    private function throttle(array $defaults = ['per_identifier' => 2, 'per_ip' => 3, 'window_seconds' => 600]): LoginThrottle
    {
        return new LoginThrottle($this->attempts, $this->settings, $defaults);
    }

    public function testNothingStopsAPersonUnderTheLimits(): void
    {
        // Arrange
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');

        // Act
        $wait = $this->throttle()->lockedFor('alice', null, '203.0.113.1');

        // Assert
        $this->assertNull($wait);
    }

    public function testAnIdentifierAtItsLimitIsLockedUntilTheOldestFailureExpires(): void
    {
        // Arrange
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice', null, false, '203.0.113.2', 'agent');

        // Act
        $wait = $this->throttle()->lockedFor('alice', null, '203.0.113.9', time() + 100);

        // Assert
        $this->assertGreaterThanOrEqual(499, $wait);
        $this->assertLessThanOrEqual(501, $wait);
    }

    public function testAnAddressAtItsLimitIsLockedWhateverItTypes(): void
    {
        // Arrange
        foreach (['a', 'b', 'c'] as $name) {
            $this->attempts->record($name, null, false, '203.0.113.1', 'agent');
        }

        // Act
        $wait = $this->throttle()->lockedFor('someone-new', null, '203.0.113.1');

        // Assert
        $this->assertNotNull($wait);
    }

    public function testFailuresOutsideTheWindowDoNotCount(): void
    {
        // Arrange
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');

        // Act
        $wait = $this->throttle()->lockedFor('alice', null, '203.0.113.1', time() + 601);

        // Assert
        $this->assertNull($wait);
    }

    public function testSavedLimitsWinOverTheConfigValues(): void
    {
        // Arrange
        $throttle = $this->throttle();
        $throttle->save(['enabled' => true, 'per_identifier' => 1, 'per_ip' => 50, 'window_minutes' => 10]);
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');

        // Act
        $wait = $throttle->lockedFor('alice', null, '203.0.113.9');

        // Assert
        $this->assertNotNull($wait);
    }

    public function testNothingStopsAnyoneWhenItIsTurnedOff(): void
    {
        // Arrange
        $throttle = $this->throttle();
        $throttle->save(['enabled' => false, 'per_identifier' => 1, 'per_ip' => 1, 'window_minutes' => 10]);
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('alice', null, false, '203.0.113.1', 'agent');

        // Act
        $wait = $throttle->lockedFor('alice', null, '203.0.113.1');

        // Assert
        $this->assertNull($wait);
    }

    public function testAResetLiftsTheLockAtOnce(): void
    {
        // Arrange
        $throttle = $this->throttle(['per_identifier' => 2, 'per_ip' => 100, 'window_seconds' => 600]);
        $this->attempts->record('ghost', null, false, '203.0.113.1', 'agent');
        $this->attempts->record('ghost', null, false, '203.0.113.1', 'agent');
        $throttle->resetIdentifier('ghost');

        // Act
        $wait = $throttle->lockedFor('ghost', null, '203.0.113.1');

        // Assert
        $this->assertNull($wait);
    }
}
