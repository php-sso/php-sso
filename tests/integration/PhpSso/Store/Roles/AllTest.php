<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'all')]
final class AllTest extends TestCase
{
    public function testItListsEveryRoleByName(): void
    {
        // Arrange
        $data = new TestData();
        $data->roles->create('reviewer', '');
        $data->roles->create('editor', '');

        // Act
        $names = array_column($data->roles->all(), 'name');

        // Assert
        $this->assertSame(['editor', 'reviewer'], $names);
    }
}
