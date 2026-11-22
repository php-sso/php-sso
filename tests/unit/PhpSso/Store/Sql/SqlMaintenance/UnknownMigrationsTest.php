<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\Migrations\Migrator;
use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlMaintenance::class, 'unknownMigrations')]
final class UnknownMigrationsTest extends TestCase
{
    public function testADatabaseThisVersionMigratedHasNothingUnknown(): void
    {
        // Arrange
        $maintenance = new SqlMaintenance(TestConnection::open(), 3600);

        // Act
        $unknown = $maintenance->unknownMigrations();

        // Assert
        $this->assertSame([], $unknown);
    }

    public function testAVersionRecordedByANewerReleaseIsUnknown(): void
    {
        // Arrange
        $db = TestConnection::open();
        $db->pdo->prepare('INSERT INTO "' . Migrator::TABLE . '" (version, applied_at) VALUES (?, ?)')->execute(['9999', 1]);

        try {
            // Act
            $unknown = (new SqlMaintenance($db, 3600))->unknownMigrations();
        } finally {
            $db->pdo->prepare('DELETE FROM "' . Migrator::TABLE . '" WHERE version = ?')->execute(['9999']);
        }

        // Assert
        $this->assertSame(['9999'], $unknown);
    }
}
