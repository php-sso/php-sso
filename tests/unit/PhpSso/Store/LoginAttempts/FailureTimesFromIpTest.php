<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\LoginAttempts;

use PhpSso\Store\LoginAttempts;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginAttempts::class, 'failureTimesFromIp')]
final class FailureTimesFromIpTest extends TestCase
{
    public function testItCountsOnlyFailuresFromThatAddress(): void
    {
        // Arrange
        $data = new TestData();
        $attempts = new LoginAttempts($data->db);
        $attempts->record('alice', null, false, '203.0.113.1', 'agent');
        $attempts->record('bob', null, false, '203.0.113.1', 'agent');
        $attempts->record('carol', null, false, '203.0.113.2', 'agent');
        $attempts->record('dave', $data->user('dave'), true, '203.0.113.1', 'agent');
        $attempts->record('erin', null, false, '203.0.113.1', 'agent', true);

        // Act
        $count = count($attempts->failureTimesFromIp('203.0.113.1', 0));

        // Assert
        $this->assertSame(2, $count);
    }
}
