<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\SqlMaintenance;

use PhpSso\Store\Sql\SqlMaintenance;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlMaintenance::class, 'isHealthy')]
final class IsHealthyTest extends TestCase
{
    public function testAWorkingDatabaseIsHealthy(): void
    {
        // Arrange
        $maintenance = new SqlMaintenance(TestConnection::open(), 3600);

        // Act
        $healthy = $maintenance->isHealthy();

        // Assert
        $this->assertTrue($healthy);
    }
}
