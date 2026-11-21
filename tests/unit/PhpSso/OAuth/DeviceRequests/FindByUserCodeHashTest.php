<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sql\SqlDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlDeviceRequests::class, 'findByUserCodeHash')]
final class FindByUserCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownUserCode(): void
    {
        // Arrange
        $store = new SqlDeviceRequests(TestConnection::open());

        // Act
        $found = $store->findByUserCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
