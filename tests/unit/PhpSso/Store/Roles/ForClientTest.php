<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'forClient')]
final class ForClientTest extends TestCase
{
    public function testItListsTheRolesForEveryClientAndTheOnesMadeAvailableInIt(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->roles->create('reviewer', '');
        $data->roles->create('editor', '', false, ['app']);

        // Act
        $names = array_column($data->roles->forClient('app'), 'name');

        // Assert
        $this->assertSame(['editor', 'reviewer'], $names);
    }

    public function testItLeavesOutRolesAvailableOnlyInOtherClients(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $data->roles->create('admin', '', false, ['other']);

        // Act
        $roles = $data->roles->forClient('app');

        // Assert
        $this->assertSame([], $roles);
    }

    public function testALimitGoesWhenTheClientIsDeletedAndTheRoleThenReachesNoClient(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $id = $data->roles->create('editor', '', false, ['app']);
        $data->db->pdo->exec("DELETE FROM clients WHERE client_id = 'app'");

        // Act
        $clientIds = $data->roles->clientIds($id);

        // Assert
        $this->assertSame([], $clientIds);
    }
}
