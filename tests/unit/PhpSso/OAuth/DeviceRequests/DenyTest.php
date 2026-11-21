<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceStatus;

#[CoversMethod(DeviceRequests::class, 'deny')]
final class DenyTest extends TestCase
{
    public function testItDeniesAPendingRequestButNotAnApprovedOne(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));
        $store->save($this->request('dev-1', 'usr-1'));
        $store->save($this->request('dev-2', 'usr-2'));
        $store->approve('dev-2', 'user-1', null);

        // Act
        $denied = $store->deny('dev-1');
        $tooLate = $store->deny('dev-2');

        // Assert
        self::assertTrue($denied);
        self::assertFalse($tooLate);
        self::assertSame(DeviceStatus::Denied, $store->findByDeviceCodeHash('dev-1')?->status);
    }

    private function request(string $device = 'dev-1', string $user = 'usr-1'): DeviceAuthorizationRecord
    {
        return new DeviceAuthorizationRecord($device, $user, 'tv', ['openid', 'profile'], 'family-1', 2_000_000_000, 5, DeviceStatus::Pending, null, null, null, null);
    }
}
