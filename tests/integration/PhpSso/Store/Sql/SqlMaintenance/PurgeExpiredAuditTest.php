<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\SqlAuditLog;
use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlMaintenance::class, 'purgeExpired')]
final class PurgeExpiredAuditTest extends TestCase
{
    public function testItRemovesAuditEntriesOlderThanTheRetentionPeriod(): void
    {
        // Arrange
        $db = TestConnection::open();
        $log = new SqlAuditLog($db);
        $log->record(['actor' => 'a', 'action' => 'old'], time() - 40 * 86400);
        $log->record(['actor' => 'a', 'action' => 'recent'], time() - 5 * 86400);

        // Act
        (new SqlMaintenance($db, 3600, 30 * 86400))->purgeExpired();

        // Assert
        $this->assertSame(['recent'], array_column($log->search([], 10), 'action'));
    }

    public function testItKeepsTheAuditLogForEverWithoutARetentionPeriod(): void
    {
        // Arrange
        $db = TestConnection::open();
        $log = new SqlAuditLog($db);
        $log->record(['actor' => 'a', 'action' => 'ancient'], 1000);

        // Act
        (new SqlMaintenance($db, 3600, 0))->purgeExpired();

        // Assert
        $this->assertSame(['ancient'], array_column($log->search([], 10), 'action'));
    }
}
