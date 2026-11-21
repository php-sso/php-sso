<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sqlite\SqliteMaintenance;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteMaintenance::class, 'isHealthy')]
final class IsHealthyTest extends TestCase
{
    public function testAWorkingDatabaseIsHealthy(): void
    {
        // Arrange
        $maintenance = new SqliteMaintenance(new Database(':memory:'), 3600);

        // Act
        $healthy = $maintenance->isHealthy();

        // Assert
        $this->assertTrue($healthy);
    }
}
