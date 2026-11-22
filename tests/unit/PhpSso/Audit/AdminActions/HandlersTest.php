<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AdminActions;

use PhpSso\Audit\AdminActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AdminActions::class, 'handlers')]
final class HandlersTest extends TestCase
{
    public function testEveryAdminPostRouteHasAnAuditAction(): void
    {
        // Arrange
        $source = (string) file_get_contents(dirname(__DIR__, 5) . '/src/Admin/AdminController.php');
        preg_match_all("/\\['POST', '[^']+', '([A-Za-z]+)'\\]/", $source, $m);
        $routes = array_values(array_unique($m[1]));

        // Act
        $recorded = AdminActions::handlers();

        // Assert
        $this->assertNotEmpty($routes);
        $this->assertSame([], array_values(array_diff($routes, $recorded)), 'a POST route of the admin panel is not in AdminActions: its changes would not be audited');
        $this->assertSame([], array_values(array_diff($recorded, $routes)), 'AdminActions names a handler that is not a POST route any more');
    }
}
