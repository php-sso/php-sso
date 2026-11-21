<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlDeviceRequests::class, 'findByDeviceCodeHash')]
final class FindByDeviceCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new SqlDeviceRequests(Connection::sqlite(':memory:'));

        // Act
        $found = $store->findByDeviceCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
