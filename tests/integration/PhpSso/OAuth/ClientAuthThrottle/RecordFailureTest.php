<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\ClientAuthThrottle;

use PhpSso\OAuth\ClientAuthThrottle;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlClientAuthFailures;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(ClientAuthThrottle::class, 'recordFailure')]
final class RecordFailureTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testFailuresOlderThanTheWindowAreForgotten(): void
    {
        // Arrange
        $db = TestConnection::open();
        $throttle = new ClientAuthThrottle(new SqlClientAuthFailures($db), ['window_seconds' => 600]);
        $throttle->recordFailure('web', '10.0.0.1', self::NOW);

        // Act
        $throttle->recordFailure('web', '10.0.0.1', self::NOW + 700);

        // Assert
        self::assertSame(1, (int) $db->pdo->query('SELECT COUNT(*) FROM client_auth_failures')->fetchColumn());
    }

    public function testNothingIsStoredWhenSwitchedOff(): void
    {
        // Arrange
        $db = TestConnection::open();
        $throttle = new ClientAuthThrottle(new SqlClientAuthFailures($db), ['enabled' => false]);

        // Act
        $throttle->recordFailure('web', '10.0.0.1', self::NOW);

        // Assert
        self::assertSame(0, (int) $db->pdo->query('SELECT COUNT(*) FROM client_auth_failures')->fetchColumn());
    }
}
