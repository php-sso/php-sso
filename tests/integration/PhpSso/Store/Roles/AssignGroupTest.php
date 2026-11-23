<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'assignGroup')]
final class AssignGroupTest extends TestCase
{
    public function testEveryoneInTheGroupGetsTheRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $staff = $data->group('staff', [$alice, $bob]);
        $role = $data->roles->create('editor', '');

        // Act
        $data->roles->assignGroup($role, $staff);

        // Assert
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice, 'app'));
        $this->assertSame(['editor'], $data->roles->effectiveFor($bob, 'app'));
    }

    public function testAssigningTheSameGroupTwiceIsHarmless(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $role = $data->roles->create('editor', '');
        $data->roles->assignGroup($role, $staff);

        // Act
        $data->roles->assignGroup($role, $staff);

        // Assert
        $this->assertSame(['editor'], $data->roles->effectiveFor($alice, 'app'));
    }
}
