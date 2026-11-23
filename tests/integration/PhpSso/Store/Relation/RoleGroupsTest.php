<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Relation;

use PhpSso\Store\Relation;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlRelation;

#[CoversMethod(Relation::class, 'roleGroups')]
final class RoleGroupsTest extends TestCase
{
    public function testAddingAGroupGivesItsMembersTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $role = $data->roles->create('editor', '');

        // Act
        SqlRelation::roleGroups($data->db)->add($role, $staff);

        // Assert
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice, 'app'));
    }

    public function testRemovingTheGroupTakesTheRoleAway(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $role = $data->roles->create('editor', '');
        $relation = SqlRelation::roleGroups($data->db);
        $relation->add($role, $staff);

        // Act
        $relation->remove($role, $staff);

        // Assert
        $this->assertSame([], $data->roles->effectiveFor($alice, 'app'));
    }

    public function testItListsTheGroupsThatHaveTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $role = $data->roles->create('editor', '');
        $data->roles->assignGroup($role, $data->group('staff'));
        $data->group('guests');

        // Act
        $page = SqlRelation::roleGroups($data->db)->page($role, '', 20, 0);

        // Assert
        $this->assertSame(['staff'], array_column($page, 'name'));
    }

    public function testItOffersOnlyGroupsThatDoNotHaveTheRoleYet(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $role = $data->roles->create('editor', '');
        $data->roles->assignGroup($role, $data->group('staff'));
        $data->group('guests');

        // Act
        $candidates = SqlRelation::roleGroups($data->db)->candidates($role, '', 8);

        // Assert
        $this->assertSame(['guests'], array_column($candidates, 'name'));
    }
}
