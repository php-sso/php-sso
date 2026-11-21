<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'findByName')]
final class FindByNameTest extends TestCase
{
    public function testItFindsTheRoleWhateverTheCase(): void
    {
        // Arrange
        $data = new TestData();
        $id = $data->roles->create('Editor', '');

        // Act
        $role = $data->roles->findByName('editor');

        // Assert
        $this->assertSame($id, $role['id'] ?? null);
    }

    public function testItReturnsNullForARoleThatDoesNotExist(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $role = $data->roles->findByName('editor');

        // Assert
        $this->assertNull($role);
    }
}
