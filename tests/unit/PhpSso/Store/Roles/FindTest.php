<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'find')]
final class FindTest extends TestCase
{
    public function testItReturnsTheRoleWithItsCounts(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $id = $data->roles->create('editor', 'May edit', false, ['app']);
        $data->roles->assignGroup($id, $data->group('staff', [$alice]));
        $data->roles->assignUser($id, $alice);

        // Act
        $role = $data->roles->find($id);

        // Assert
        $this->assertSame(
            ['id' => $id, 'name' => 'editor', 'description' => 'May edit', 'all_clients' => false, 'client_count' => 1, 'group_count' => 1, 'user_count' => 1],
            $role,
        );
    }

    public function testItReturnsNullForARoleThatDoesNotExist(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $role = $data->roles->find(999);

        // Assert
        $this->assertNull($role);
    }
}
