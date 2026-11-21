<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\DeviceRequests;

use PhpSso\Store\Sql\SqlDeviceRequests;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\DeviceAuthorizationRecord;
use PhpSso\OAuthServer\Grant\DeviceStatus;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlDeviceRequests::class, 'recordPoll')]
final class RecordPollTest extends TestCase
{
    public function testItRemembersThePollAndTheIntervalNowRequired(): void
    {
        // Arrange
        $store = new SqlDeviceRequests(TestConnection::open());
        $store->save($this->request());

        // Act
        $store->recordPoll('dev-1', 1_700_000_000, 10);

        // Assert
        $found = $store->findByDeviceCodeHash('dev-1');
        self::assertSame(1_700_000_000, $found?->lastPolledAt);
        self::assertSame(10, $found->interval);
    }

    private function request(string $device = 'dev-1', string $user = 'usr-1'): DeviceAuthorizationRecord
    {
        return new DeviceAuthorizationRecord($device, $user, 'tv', ['openid', 'profile'], 'family-1', 2_000_000_000, 5, DeviceStatus::Pending, null, null, null, null);
    }
}
