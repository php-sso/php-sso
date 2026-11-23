<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlMaintenance;
use PhpSso\Store\Sql\Migrations\Migration;
use PhpSso\Store\Sql\Migrations\Migrations;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

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
        $this->assertSame(array_map(static fn (Migration $m): string => $m->id(), Migrations::all()), $pending);
    }
}
