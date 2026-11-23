<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\Migrations\Migrator;

use PhpSso\Store\Sql\Migrations\Migrator;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\FakeMigration;
use Tests\integration\PhpSso\Support\UsesMigrationSandbox;

#[CoversMethod(Migrator::class, 'status')]
final class StatusTest extends TestCase
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

    public function testItListsEveryMigrationWithWhenItRanOrNullWhenPending(): void
    {
        // Arrange
        $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a')])->migrate();
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $status = $migrator->status();

        // Assert
        $this->assertSame(['0001', '0002'], array_map(static fn (array $row): string => $row['migration']->id(), $status));
        $this->assertIsInt($status[0]['appliedAt']);
        $this->assertNull($status[1]['appliedAt']);
    }
}
