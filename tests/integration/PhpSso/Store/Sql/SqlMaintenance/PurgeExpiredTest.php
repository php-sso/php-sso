<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlMaintenance::class, 'purgeExpired')]
final class PurgeExpiredTest extends TestCase
{
    public function testItDropsOldRowsAndKeepsRecentOnes(): void
    {
        // Arrange
        $db = TestConnection::open();
        $insert = $db->pdo->prepare('INSERT INTO device_failures (user_sub, created_at) VALUES (?, ?)');
        $insert->execute(['old', time() - 8 * 86400]);
        $insert->execute(['recent', time() - 3600]);

        // Act
        (new SqlMaintenance($db, 3600))->purgeExpired();

        // Assert
        $this->assertSame(['recent'], $db->pdo->query('SELECT user_sub FROM device_failures')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
