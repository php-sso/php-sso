<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteDeviceRequests::class, 'findByDeviceCodeHash')]
final class FindByDeviceCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new SqliteDeviceRequests(new Database(':memory:'));

        // Act
        $found = $store->findByDeviceCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
