<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Relation;

use PhpSso\Store\Relation;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlRelation;

#[CoversMethod(Relation::class, 'roleUsers')]
final class RoleUsersTest extends TestCase
{
    public function testAddingAPersonGivesThemTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $role = $data->roles->create('admin', '');

        // Act
        SqlRelation::roleUsers($data->db)->add($role, $alice);

        // Assert
        $this->assertSame(['admin'], $data->roles->effectiveFor($alice, 'app'));
    }

    public function testRemovingEveryoneTakesTheRoleAwayFromAll(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $role = $data->roles->create('admin', '');
        $relation = SqlRelation::roleUsers($data->db);
        $relation->addMany($role, [$alice, $bob]);

        // Act
        $relation->removeMatching($role, '');

        // Assert
        $this->assertSame([], $data->roles->effectiveFor($alice, 'app'));
        $this->assertSame([], $data->roles->effectiveFor($bob, 'app'));
    }

    public function testItOffersOnlyPeopleWhoDoNotHaveTheRoleYet(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->user('bob');
        $role = $data->roles->create('admin', '');
        $data->roles->assignUser($role, $alice);

        // Act
        $candidates = SqlRelation::roleUsers($data->db)->candidates($role, '', 8);

        // Assert
        $this->assertSame(['Bob'], array_column($candidates, 'name'));
    }
}
