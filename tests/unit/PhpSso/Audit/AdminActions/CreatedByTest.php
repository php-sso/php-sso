<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AdminActions;

use PhpSso\Audit\AdminActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AdminActions::class, 'createdBy')]
final class CreatedByTest extends TestCase
{
    public function testItFindsWhatWasMadeInTheAddressTheBrowserWasSentTo(): void
    {
        // Arrange
        $location = '/admin/users/3f2a%2Fb/edit?saved=1';

        // Act
        $made = AdminActions::createdBy($location);

        // Assert
        $this->assertSame(['user', '3f2a/b'], $made);
    }

    public function testAListOrAnUnknownPageSaysNothing(): void
    {
        // Arrange
        $locations = ['/admin/users', '/admin/users/new', '/admin/dashboard/5', '/login'];

        // Act
        $found = array_map(AdminActions::createdBy(...), $locations);

        // Assert
        $this->assertSame([null, null, null, null], $found);
    }
}
