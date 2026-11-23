<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'setClients')]
final class SetClientsTest extends TestCase
{
    public function testItReplacesTheClientsTheRoleIsAvailableIn(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $id = $data->roles->create('editor', '', false, ['app']);

        // Act
        $data->roles->setClients($id, false, ['other']);

        // Assert
        $this->assertSame(['other'], $data->roles->clientIds($id));
    }

    public function testSwitchingToEveryClientKeepsTheListForLater(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $id = $data->roles->create('editor', '', false, ['app']);

        // Act
        $data->roles->setClients($id, true, []);

        // Assert
        $this->assertSame(['app'], $data->roles->clientIds($id));
        $this->assertTrue($data->roles->find($id)['all_clients'] ?? false);
    }

    public function testARoleForEveryClientReachesAClientNotInTheList(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $alice = $data->user('alice');
        $id = $data->roles->create('editor', '', false, ['app']);
        $data->roles->assignUser($id, $alice);

        // Act
        $data->roles->setClients($id, true, []);

        // Assert
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice, 'other'));
    }
}
