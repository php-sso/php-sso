<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteDeviceRequests::class, 'findByUserCodeHash')]
final class FindByUserCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownUserCode(): void
    {
        // Arrange
        $store = new SqliteDeviceRequests(new Database(':memory:'));

        // Act
        $found = $store->findByUserCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
