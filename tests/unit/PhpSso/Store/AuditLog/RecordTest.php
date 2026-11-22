<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\AuditLog;

use PhpSso\Store\AuditLog;
use PhpSso\Store\Sql\SqlAuditLog;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(AuditLog::class, 'record')]
final class RecordTest extends TestCase
{
    public function testItKeepsWhoDidWhatToWhomAndWhen(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());

        // Act
        $log->record(['actor_sub' => 'sub-1', 'actor' => 'admin', 'ip' => '203.0.113.9', 'action' => 'user.lock', 'target_type' => 'user', 'target' => 'sub-2',
            'target_label' => 'alice', 'detail' => ['reason' => 'ü and "quotes"', 'count' => 3]], 1_800_000_000);

        // Assert
        $rows = $log->search([], 10);
        $this->assertCount(1, $rows);
        $this->assertSame(1_800_000_000, $rows[0]['at']);
        $this->assertSame('sub-1', $rows[0]['actor_sub']);
        $this->assertSame('admin', $rows[0]['actor']);
        $this->assertSame('203.0.113.9', $rows[0]['ip']);
        $this->assertSame('user.lock', $rows[0]['action']);
        $this->assertSame('user', $rows[0]['target_type']);
        $this->assertSame('sub-2', $rows[0]['target']);
        $this->assertSame('alice', $rows[0]['target_label']);
        $this->assertSame(['reason' => 'ü and "quotes"', 'count' => 3], $rows[0]['detail']);
    }

    public function testAnEntryWithOnlyAnActorAndAnActionIsFine(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());

        // Act
        $log->record(['actor' => 'cli:deploy', 'action' => 'keys.rotate']);

        // Assert
        $row = $log->search([], 10)[0];
        $this->assertNull($row['actor_sub']);
        $this->assertNull($row['target']);
        $this->assertSame('', $row['ip']);
        $this->assertSame([], $row['detail']);
    }

    public function testItCutsTextThatIsTooLongForItsColumn(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());

        // Act
        $log->record(['actor' => str_repeat('a', 400), 'action' => str_repeat('b', 300), 'target' => str_repeat('c', 400)]);

        // Assert
        $row = $log->search([], 10)[0];
        $this->assertSame(255, mb_strlen($row['actor']));
        $this->assertSame(100, mb_strlen($row['action']));
        $this->assertSame(255, mb_strlen((string) $row['target']));
    }

    public function testItDefaultsTheTimeToNow(): void
    {
        // Arrange
        $log = new SqlAuditLog(TestConnection::open());
        $before = time();

        // Act
        $log->record(['actor' => 'admin', 'action' => 'user.create']);

        // Assert
        $this->assertGreaterThanOrEqual($before, $log->search([], 10)[0]['at']);
    }
}
