<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sqlite\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceStatus;

#[CoversMethod(DeviceRequests::class, 'save')]
final class SaveTest extends TestCase
{
    public function testItKeepsEveryFieldAndFindsTheRequestByEitherCode(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));
        $request = $this->request();

        // Act
        $store->save($request);

        // Assert
        self::assertEquals($request, $store->findByDeviceCodeHash('dev-1'));
        self::assertEquals($request, $store->findByUserCodeHash('usr-1'));
    }

    private function request(string $device = 'dev-1', string $user = 'usr-1'): DeviceAuthorizationRecord
    {
        return new DeviceAuthorizationRecord($device, $user, 'tv', ['openid', 'profile'], 'family-1', 2_000_000_000, 5, DeviceStatus::Pending, null, null, null, null);
    }
}
