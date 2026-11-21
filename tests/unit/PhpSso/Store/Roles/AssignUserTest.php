<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'assignUser')]
final class AssignUserTest extends TestCase
{
    public function testOnlyThatPersonGetsTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $role = $data->roles->create('admin', '');

        // Act
        $data->roles->assignUser($role, $alice);

        // Assert
        $this->assertSame(['admin'], $data->roles->effectiveFor($alice, 'app'));
        $this->assertSame([], $data->roles->effectiveFor($bob, 'app'));
    }

    public function testAssigningTheSamePersonTwiceIsHarmless(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $role = $data->roles->create('admin', '');
        $data->roles->assignUser($role, $alice);

        // Act
        $data->roles->assignUser($role, $alice);

        // Assert
        $this->assertSame(['admin'], $data->roles->effectiveFor($alice, 'app'));
    }

    public function testDeletingThePersonTakesTheRoleAway(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('admin', ''), $alice);
        $data->db->pdo->prepare('DELETE FROM users WHERE sub = ?')->execute([$alice]);

        // Act
        $count = (int) $data->db->pdo->query('SELECT COUNT(*) FROM role_user')->fetchColumn();

        // Assert
        $this->assertSame(0, $count);
    }
}
