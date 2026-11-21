<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\StorageFactory;

use InvalidArgumentException;
use PhpSso\Store\Sqlite\SqliteStorage;
use PhpSso\Store\StorageFactory;
use PhpSso\Store\StorageOptions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(StorageFactory::class, 'create')]
final class CreateTest extends TestCase
{
    public function testItBuildsTheSqliteStorageByDefault(): void
    {
        // Arrange
        $options = new StorageOptions(3600, ['openid']);

        // Act
        $storage = StorageFactory::create(['path' => ':memory:'], $options);

        // Assert
        $this->assertInstanceOf(SqliteStorage::class, $storage);
    }

    public function testItRefusesADriverItDoesNotKnow(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown storage driver "mongo"');

        // Act
        StorageFactory::create(['driver' => 'mongo'], new StorageOptions(3600, []));

        // Assert (the exception above)
    }

    public function testItNeedsAPathForSqlite(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        StorageFactory::create(['driver' => 'sqlite'], new StorageOptions(3600, []));

        // Assert (the exception above)
    }
}
