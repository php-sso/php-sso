<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(DeviceRequests::class, 'findByUserCodeHash')]
final class FindByUserCodeHashTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownUserCode(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));

        // Act
        $found = $store->findByUserCodeHash('nope');

        // Assert
        self::assertNull($found);
    }
}
