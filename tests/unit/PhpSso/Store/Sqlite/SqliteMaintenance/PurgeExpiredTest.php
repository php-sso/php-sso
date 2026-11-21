<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sqlite\SqliteMaintenance;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteMaintenance::class, 'purgeExpired')]
final class PurgeExpiredTest extends TestCase
{
    public function testItDropsOldRowsAndKeepsRecentOnes(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $insert = $db->pdo->prepare('INSERT INTO device_failures (user_sub, created_at) VALUES (?, ?)');
        $insert->execute(['old', time() - 8 * 86400]);
        $insert->execute(['recent', time() - 3600]);

        // Act
        (new SqliteMaintenance($db, 3600))->purgeExpired();

        // Assert
        $this->assertSame(['recent'], $db->pdo->query('SELECT user_sub FROM device_failures')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
