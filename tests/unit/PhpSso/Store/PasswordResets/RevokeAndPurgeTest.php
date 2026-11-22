<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\PasswordResets;

use PhpSso\Store\PasswordResets;
use PhpSso\Store\Sql\SqlMaintenance;
use PhpSso\Store\Sql\SqlPasswordResets;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(PasswordResets::class, 'revokeFor')]
#[CoversMethod(PasswordResets::class, 'deleteOlderThan')]
final class RevokeAndPurgeTest extends TestCase
{
    public function testRevokingStopsTheTokensOfThatPersonButKeepsTheCount(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'one', 9000, '203.0.113.5', 1000);
        $resets->issue('sub-2', 'two', 9000, '203.0.113.5', 1000);

        // Act
        $resets->revokeFor('sub-1');

        // Assert
        $this->assertNull($resets->find('one', 1001));
        $this->assertNotNull($resets->find('two', 1001));
        $this->assertSame(1, $resets->requestsFor('sub-1', 0));
    }

    public function testOldEntriesAreRemovedAndNewOnesStay(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->record('203.0.113.5', 1000);
        $resets->issue('sub-1', 'recent', 9000, '203.0.113.5', 5000);

        // Act
        $removed = $resets->deleteOlderThan(2000);

        // Assert
        $this->assertSame(1, $removed);
        $this->assertNotNull($resets->find('recent', 5001));
    }

    public function testHousekeepingRemovesWhatIsADayOld(): void
    {
        // Arrange
        $db = TestConnection::open();
        $resets = new SqlPasswordResets($db);
        $resets->record('203.0.113.5', time() - 2 * 86400);
        $resets->record('203.0.113.6', time() - 60);

        // Act
        (new SqlMaintenance($db, 3600))->purgeExpired();

        // Assert
        $this->assertSame(0, $resets->requestsFrom('203.0.113.5', 0));
        $this->assertSame(1, $resets->requestsFrom('203.0.113.6', 0));
    }
}
