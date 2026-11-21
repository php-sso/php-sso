<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sqlite\Database;
use PhpSso\OAuth\DeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceStatus;

#[CoversMethod(DeviceRequests::class, 'markConsumed')]
final class MarkConsumedTest extends TestCase
{
    public function testARequestPaysOutOnlyOnce(): void
    {
        // Arrange
        $store = new DeviceRequests(new Database(':memory:'));
        $store->save($this->request());

        // Act
        $first = $store->markConsumed('dev-1', 1_700_000_000);
        $second = $store->markConsumed('dev-1', 1_700_000_001);

        // Assert
        self::assertTrue($first);
        self::assertFalse($second);
    }

    private function request(string $device = 'dev-1', string $user = 'usr-1'): DeviceAuthorizationRecord
    {
        return new DeviceAuthorizationRecord($device, $user, 'tv', ['openid', 'profile'], 'family-1', 2_000_000_000, 5, DeviceStatus::Pending, null, null, null, null);
    }
}
