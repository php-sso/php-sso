<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\AuditLog;

use PhpSso\Store\AuditLog;
use PhpSso\Store\Sql\SqlAuditLog;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(AuditLog::class, 'count')]
final class CountTest extends TestCase
{
    public function testItCountsEveryRowWithoutAFilterAndOnlyTheMatchingOnesWithOne(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());
        $log->record(['actor' => 'alice', 'action' => 'user.create'], 1000);
        $log->record(['actor' => 'alice', 'action' => 'user.lock'], 2000);
        $log->record(['actor' => 'bob', 'action' => 'client.delete'], 3000);

        // Act
        $all = $log->count([]);
        $users = $log->count(['action' => 'user.']);
        $recent = $log->count(['since' => 2500]);

        // Assert
        $this->assertSame(3, $all);
        $this->assertSame(2, $users);
        $this->assertSame(1, $recent);
    }
}
