<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(DeviceRequests::class, 'findByDeviceCodeHash')]
final class FindByDeviceCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));

        // Act
        $found = $store->findByDeviceCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
