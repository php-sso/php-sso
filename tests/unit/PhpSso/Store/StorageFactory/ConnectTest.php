<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\StorageFactory;

use PhpSso\Store\StorageFactory;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(StorageFactory::class, 'connect')]
final class ConnectTest extends TestCase
{
    public function testItMigratesByDefault(): void
    {
        // Arrange
        $config = ['driver' => 'sqlite', 'path' => ':memory:'];

        // Act
        $db = StorageFactory::connect($config);

        // Assert
        $this->assertSame([], $db->migrator()->pending());
    }

    public function testItDoesNotMigrateWhenTheConfigSaysNo(): void
    {
        // Arrange
        $config = ['driver' => 'sqlite', 'path' => ':memory:', 'auto_migrate' => false];

        // Act
        $db = StorageFactory::connect($config);

        // Assert
        $this->assertCount(1, $db->migrator()->pending());
    }

    public function testTheCallerMayOverrideTheConfig(): void
    {
        // Arrange
        $config = ['driver' => 'sqlite', 'path' => ':memory:', 'auto_migrate' => false];

        // Act
        $db = StorageFactory::connect($config, migrate: true);

        // Assert
        $this->assertSame([], $db->migrator()->pending());
    }
}
