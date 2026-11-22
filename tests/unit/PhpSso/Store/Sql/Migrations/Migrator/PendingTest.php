<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Migrations\Migrator;

use PhpSso\Store\Sql\Migrations\Migrator;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeMigration;
use Tests\unit\PhpSso\Support\UsesMigrationSandbox;

#[CoversMethod(Migrator::class, 'pending')]
final class PendingTest extends TestCase
{
    use UsesMigrationSandbox;

    protected function setUp(): void
    {
        $this->setUpSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownSandbox();
    }

    public function testEveryMigrationIsPendingWhenNoneRan(): void
    {
        // Arrange
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $pending = $migrator->pending();

        // Assert
        $this->assertSame(['0001', '0002'], array_map(static fn ($m): string => $m->id(), $pending));
    }

    public function testOnlyTheMigrationsThatDidNotRunArePending(): void
    {
        // Arrange
        $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a')])->migrate();
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $pending = $migrator->pending();

        // Assert
        $this->assertSame(['0002'], array_map(static fn ($m): string => $m->id(), $pending));
    }

    public function testNothingIsPendingWhenEverythingRan(): void
    {
        // Arrange
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a')]);
        $migrator->migrate();

        // Act
        $pending = $migrator->pending();

        // Assert
        $this->assertSame([], $pending);
    }

    public function testTheAppsOwnMigrationsHaveAllRunOnTheDatabaseUnderTest(): void
    {
        // Arrange
        $migrator = $this->db->migrator();

        // Act
        $pending = $migrator->pending();

        // Assert
        $this->assertSame([], $pending);
    }
}
