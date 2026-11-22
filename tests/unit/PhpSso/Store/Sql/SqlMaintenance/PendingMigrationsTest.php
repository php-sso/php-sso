<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlMaintenance::class, 'pendingMigrations')]
final class PendingMigrationsTest extends TestCase
{
    public function testAMigratedDatabaseHasNothingPending(): void
    {
        // Arrange
        $maintenance = new SqlMaintenance(TestConnection::open(), 3600);

        // Act
        $pending = $maintenance->pendingMigrations();

        // Assert
        $this->assertSame([], $pending);
    }

    public function testADatabaseOpenedWithoutMigratingHasTheBaselinePending(): void
    {
        // Arrange
        $maintenance = new SqlMaintenance(Connection::sqlite(':memory:', migrate: false), 3600);

        // Act
        $pending = $maintenance->pendingMigrations();

        // Assert
        $this->assertSame(['0001'], $pending);
    }
}
