<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceStatus;

#[CoversMethod(DeviceRequests::class, 'approve')]
final class ApproveTest extends TestCase
{
    public function testItApprovesAPendingRequestOnceAndRemembersWho(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));
        $store->save($this->request());

        // Act
        $first = $store->approve('dev-1', 'user-1', 'session-1');
        $second = $store->approve('dev-1', 'user-2', null);

        // Assert
        self::assertTrue($first);
        self::assertFalse($second);
        $found = $store->findByDeviceCodeHash('dev-1');
        self::assertSame(DeviceStatus::Approved, $found?->status);
        self::assertSame('user-1', $found->userId);
        self::assertSame('session-1', $found->sessionId);
    }

    private function request(string $device = 'dev-1', string $user = 'usr-1'): DeviceAuthorizationRecord
    {
        return new DeviceAuthorizationRecord($device, $user, 'tv', ['openid', 'profile'], 'family-1', 2_000_000_000, 5, DeviceStatus::Pending, null, null, null, null);
    }
}
