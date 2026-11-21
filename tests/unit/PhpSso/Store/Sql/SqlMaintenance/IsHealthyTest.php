<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlMaintenance::class, 'isHealthy')]
final class IsHealthyTest extends TestCase
{
    public function testAWorkingDatabaseIsHealthy(): void
    {
        // Arrange
        $maintenance = new SqlMaintenance(Connection::sqlite(':memory:'), 3600);

        // Act
        $healthy = $maintenance->isHealthy();

        // Assert
        $this->assertTrue($healthy);
    }
}
