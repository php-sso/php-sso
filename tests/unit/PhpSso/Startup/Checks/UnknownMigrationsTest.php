<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Startup\Checks;

use PhpSso\Startup\Checks;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Checks::class, 'unknownMigrations')]
final class UnknownMigrationsTest extends TestCase
{
    public function testADatabaseWithNothingUnknownIsFine(): void
    {
        // Arrange
        $unknown = [];

        // Act
        $problems = Checks::unknownMigrations($unknown);

        // Assert
        $this->assertSame([], $problems);
    }

    public function testADatabaseAnewerVersionUpgradedIsRefusedAndTheMigrationsAreNamed(): void
    {
        // Arrange
        $unknown = ['0004', '0005'];

        // Act
        $problems = Checks::unknownMigrations($unknown);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('0004, 0005', $problems[0]);
        $this->assertStringContainsString('newer version', $problems[0]);
        $this->assertStringContainsString('restore a backup', $problems[0]);
    }
}
