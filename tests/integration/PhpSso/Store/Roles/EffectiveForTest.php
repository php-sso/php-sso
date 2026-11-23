<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'effectiveFor')]
final class EffectiveForTest extends TestCase
{
    public function testAPersonHasARoleGivenToThemDirectly(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('editor', ''), $alice);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame(['editor'], $roles);
    }

    public function testAPersonHasARoleGivenToAGroupTheyAreIn(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $data->roles->assignGroup($data->roles->create('editor', ''), $staff);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame(['editor'], $roles);
    }

    public function testARoleGivenBothWaysIsListedOnce(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $editor = $data->roles->create('editor', '');
        $data->roles->assignUser($editor, $alice);
        $data->roles->assignGroup($editor, $staff);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame(['editor'], $roles);
    }

    public function testAPersonInSeveralGroupsGetsTheRolesOfEachSortedByName(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->roles->assignGroup($data->roles->create('reviewer', ''), $data->group('staff', [$alice]));
        $data->roles->assignGroup($data->roles->create('editor', ''), $data->group('writers', [$alice]));

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame(['editor', 'reviewer'], $roles);
    }

    public function testARoleAvailableOnlyInAnotherClientIsNotIncluded(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('admin', '', false, ['other']), $alice);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame([], $roles);
    }

    public function testAPersonNotInTheGroupDoesNotHaveItsRole(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $data->roles->assignGroup($data->roles->create('editor', ''), $data->group('staff', [$alice]));

        // Act
        $roles = $data->roles->effectiveFor($bob, 'app');

        // Assert
        $this->assertSame([], $roles);
    }

    public function testALeftGroupTakesItsRolesAway(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $data->roles->assignGroup($data->roles->create('editor', ''), $staff);
        $data->users->setGroups($alice, []);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame([], $roles);
    }

    public function testADeletedGroupTakesItsRolesAway(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $staff = $data->group('staff', [$alice]);
        $data->roles->assignGroup($data->roles->create('editor', ''), $staff);
        $data->groups->delete($staff);

        // Act
        $roles = $data->roles->effectiveFor($alice, 'app');

        // Assert
        $this->assertSame([], $roles);
    }

    public function testARoleForEveryClientIsIncludedWhicheverClientAsks(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('editor', ''), $alice);

        // Act
        $roles = [$data->roles->effectiveFor($alice, 'app'), $data->roles->effectiveFor($alice, 'other')];

        // Assert
        $this->assertSame([['editor'], ['editor']], $roles);
    }

    public function testARoleDefinedOnceReachesEveryClientItIsAvailableIn(): void
    {
        // Arrange
        $data = new TestData();
        foreach (['website', 'mobile', 'tv', 'extension'] as $client) {
            $data->client($client);
        }
        $alice = $data->user('alice');
        $editor = $data->roles->create('editor', '', false, ['website', 'mobile', 'tv']);
        $data->roles->assignGroup($editor, $data->group('staff', [$alice]));

        // Act
        $roles = array_map(fn (string $c): array => $data->roles->effectiveFor($alice, $c), ['website', 'mobile', 'tv', 'extension']);

        // Assert
        $this->assertSame([['editor'], ['editor'], ['editor'], []], $roles);
    }
}
