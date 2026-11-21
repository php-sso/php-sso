<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlDeviceRequests::class, 'findByUserCodeHash')]
final class FindByUserCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownUserCode(): void
    {
        // Arrange
        $store = new SqlDeviceRequests(Connection::sqlite(':memory:'));

        // Act
        $found = $store->findByUserCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
