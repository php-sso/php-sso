<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\AuditLog;

use PhpSso\Store\AuditLog;
use PhpSso\Store\Sql\SqlAuditLog;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(AuditLog::class, 'deleteOlderThan')]
final class DeleteOlderThanTest extends TestCase
{
    public function testItRemovesOnlyTheRowsBeforeTheTimeAndSaysHowMany(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());
        $log->record(['actor' => 'a', 'action' => 'one'], 1000);
        $log->record(['actor' => 'a', 'action' => 'two'], 2000);
        $log->record(['actor' => 'a', 'action' => 'three'], 3000);

        // Act
        $removed = $log->deleteOlderThan(2000);

        // Assert
        $this->assertSame(1, $removed);
        $this->assertSame(['three', 'two'], array_column($log->search([], 10), 'action'));
    }
}
