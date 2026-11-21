<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'delete')]
final class DeleteTest extends TestCase
{
    public function testEveryoneLosesTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $id = $data->roles->create('editor', '');
        $data->roles->assignUser($id, $alice);
        $data->roles->assignGroup($id, $data->group('staff', [$bob]));

        // Act
        $data->roles->delete($id);

        // Assert
        $this->assertSame([], $data->roles->effectiveFor($alice, 'app'));
        $this->assertSame([], $data->roles->effectiveFor($bob, 'app'));
    }

    public function testTheOtherRolesOfTheClientStay(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $editor = $data->roles->create('editor', '');
        $data->roles->create('admin', '');

        // Act
        $data->roles->delete($editor);

        // Assert
        $this->assertSame(['admin'], array_column($data->roles->forClient('app'), 'name'));
    }
}
