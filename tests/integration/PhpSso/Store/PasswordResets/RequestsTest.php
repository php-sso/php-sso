<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\PasswordResets;

use PhpSso\Store\PasswordResets;
use PhpSso\Store\Sql\SqlPasswordResets;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(PasswordResets::class, 'requestsFrom')]
#[CoversMethod(PasswordResets::class, 'requestsFor')]
#[CoversMethod(PasswordResets::class, 'record')]
final class RequestsTest extends TestCase
{
    public function testAddressesCountEveryRequestWhetherOrNotItMadeAToken(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->record('203.0.113.5', 1000);
        $resets->issue('sub-1', 'hash-1', 5000, '203.0.113.5', 1100);
        $resets->record('198.51.100.7', 1200);

        // Act
        $count = $resets->requestsFrom('203.0.113.5', 0);

        // Assert
        $this->assertSame(2, $count);
        $this->assertSame(1, $resets->requestsFrom('198.51.100.7', 0));
    }

    public function testPeopleCountOnlyTheRequestsThatMadeATokenForThemAndOnlyWithinTheWindow(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->record('203.0.113.5', 1000);
        $resets->issue('sub-1', 'a', 9000, '203.0.113.5', 1000);
        $resets->issue('sub-1', 'b', 9000, '203.0.113.5', 2000);
        $resets->issue('sub-2', 'c', 9000, '203.0.113.5', 2000);

        // Act
        $all = $resets->requestsFor('sub-1', 0);
        $recent = $resets->requestsFor('sub-1', 1500);

        // Assert
        $this->assertSame(2, $all);
        $this->assertSame(1, $recent);
    }

    public function testASincePointBeforeTheRequestIncludesItAndOneAfterDoesNot(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->record('203.0.113.5', 1000);

        // Act
        $included = $resets->requestsFrom('203.0.113.5', 1000);
        $excluded = $resets->requestsFrom('203.0.113.5', 1001);

        // Assert
        $this->assertSame(1, $included);
        $this->assertSame(0, $excluded);
    }
}
