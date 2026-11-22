<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Startup\Checks;

use PhpSso\Startup\Checks;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Checks::class, 'pendingMigrations')]
final class PendingMigrationsTest extends TestCase
{
    public function testNoPendingMigrationsIsFine(): void
    {
        // Arrange
        $pending = [];

        // Act
        $problems = Checks::pendingMigrations($pending);

        // Assert
        $this->assertSame([], $problems);
    }

    public function testPendingMigrationsAreRefusedAndNamed(): void
    {
        // Arrange
        $pending = ['0002', '0003'];

        // Act
        $problems = Checks::pendingMigrations($pending);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('0002, 0003', $problems[0]);
        $this->assertStringContainsString('bin/migrate up', $problems[0]);
    }
}
