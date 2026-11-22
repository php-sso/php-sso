<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Migrations\Migrator;

use PhpSso\Store\Sql\Migrations\Migrator;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeMigration;
use Tests\unit\PhpSso\Support\UsesMigrationSandbox;

#[CoversMethod(Migrator::class, 'unknown')]
final class UnknownTest extends TestCase
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

    public function testItNamesTheVersionsANewerVersionApplied(): void
    {
        // Arrange
        $this->migrator([FakeMigration::noop('0001'), FakeMigration::noop('0002'), FakeMigration::noop('0003')])->migrate();
        $older = $this->migrator([FakeMigration::noop('0001')]);

        // Act
        $unknown = $older->unknown();

        // Assert
        $this->assertSame(['0002', '0003'], $unknown);
    }

    public function testThereIsNothingUnknownWhenTheDatabaseHasOnlyWhatThisVersionKnows(): void
    {
        // Arrange
        $migrator = $this->migrator([FakeMigration::noop('0001'), FakeMigration::noop('0002')]);
        $migrator->migrate();

        // Act
        $unknown = $migrator->unknown();

        // Assert
        $this->assertSame([], $unknown);
    }

    public function testAMigrationThatIsMerelyPendingIsNotUnknown(): void
    {
        // Arrange
        $this->migrator([FakeMigration::noop('0001')])->migrate();
        $newer = $this->migrator([FakeMigration::noop('0001'), FakeMigration::noop('0002')]);

        // Act
        $unknown = $newer->unknown();

        // Assert
        $this->assertSame([], $unknown);
    }
}
