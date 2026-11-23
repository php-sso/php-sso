<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\ScimServer\ScimGroupService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimGroup;
use PhpSso\ScimServer\ScimGroupService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\ScimServer\Support;

#[CoversMethod(ScimGroupService::class, 'replace')]
#[CoversMethod(ScimGroupService::class, 'patch')]
#[CoversMethod(ScimGroupService::class, 'delete')]
#[CoversMethod(ScimGroupService::class, 'get')]
final class ChangeTest extends TestCase
{
    private function person(Support $app, string $userName): string
    {
        return $app->service->create($app->client(), Support::payload($userName))->sub;
    }

    private function group(Support $app, string $name = 'Engineering', array $members = []): ScimGroup
    {
        return $app->groupService->create($app->client(), ['displayName' => $name, 'members' => array_map(static fn (string $s): array => ['value' => $s], $members)]);
    }

    public function testAddingMembersLeavesTheOthersAlone(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client(), $group->id, [['op' => 'add', 'path' => 'members', 'value' => [['value' => $bob]]]]);

        // Assert
        $this->assertEqualsCanonicalizing([$alice, $bob], $app->scimGroups->members($group->id));
    }

    public function testRemovingOneMemberByFilterLeavesTheOthers(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice, $bob]);

        // Act
        $app->groupService->patch($app->client(), $group->id, [['op' => 'remove', 'path' => 'members[value eq "' . $alice . '"]']]);

        // Assert
        $this->assertSame([$bob], $app->scimGroups->members($group->id));
    }

    public function testRemovingEveryoneEmptiesTheGroup(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->group($app, 'Engineering', [$this->person($app, 'alice@acme.com')]);

        // Act
        $app->groupService->patch($app->client(), $group->id, [['op' => 'remove', 'path' => 'members']]);

        // Assert
        $this->assertSame([], $app->scimGroups->members($group->id));
    }

    public function testRemovingSomeoneWhoIsNotInTheGroupIsHarmless(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client(), $group->id, [['op' => 'remove', 'path' => 'members[value eq "nobody"]']]);

        // Assert
        $this->assertSame([$alice], $app->scimGroups->members($group->id));
    }

    public function testReplacingTheMembersMakesExactlyThoseTheMembers(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->patch($app->client(), $group->id, [['op' => 'replace', 'path' => 'members', 'value' => [['value' => $bob]]]]);

        // Assert
        $this->assertSame([$bob], $app->scimGroups->members($group->id));
    }

    public function testASingleBadMemberChangesNothingAtAll(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $stranger = $app->data->user('stranger');
        $group = $this->group($app, 'Engineering');

        // Act
        $patch = fn () => $app->groupService->patch($app->client(), $group->id, [
            ['op' => 'replace', 'path' => 'displayName', 'value' => 'Renamed'],
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $alice], ['value' => $stranger]]],
        ]);

        // Assert
        try {
            $patch();
            $this->fail('A stranger was added.');
        } catch (ScimException $e) {
            $this->assertSame(400, $e->status);
        }
        $this->assertSame([], $app->scimGroups->members($group->id));
        $this->assertSame('Engineering', $app->data->groups->find($group->id)['name']);
    }

    public function testRenamingChangesTheNameAppsSee(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com'], 'email', 'acme:');
        $group = $this->group($app, 'Engineering');

        // Act
        $result = $app->groupService->patch($app->client(), $group->id, [['op' => 'replace', 'path' => 'displayName', 'value' => 'Platform Team']]);

        // Assert
        $this->assertSame(['acme:Platform-Team', 'Platform Team'], [$result['group']->name, $result['group']->displayName]);
    }

    public function testRenamingToAnExistingGroupIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->data->groups->create('Taken', 'Mine');
        $group = $this->group($app, 'Engineering');

        // Act
        $rename = fn () => $app->groupService->patch($app->client(), $group->id, [['op' => 'replace', 'path' => 'displayName', 'value' => 'taken']]);

        // Assert
        try {
            $rename();
            $this->fail('A group of yours was overwritten by a rename.');
        } catch (ScimException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testReplaceSetsTheWholeGroup(): void
    {
        // Arrange
        $app = new Support();
        [$alice, $bob] = [$this->person($app, 'alice@acme.com'), $this->person($app, 'bob@acme.com')];
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $replaced = $app->groupService->replace($app->client(), $group->id, ['displayName' => 'Engineering', 'externalId' => 'new-ext', 'members' => [['value' => $bob]]]);

        // Assert
        $this->assertSame('new-ext', $replaced->externalId);
        $this->assertSame([$bob], $app->scimGroups->members($group->id));
    }

    public function testAClientCannotTouchAGroupItDidNotCreate(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->data->groups->create('Staff', 'Mine');

        // Act
        $touch = fn () => $app->groupService->patch($app->client(), $mine, [['op' => 'add', 'path' => 'members', 'value' => []]]);

        // Assert
        try {
            $touch();
            $this->fail('A group of yours was changed.');
        } catch (ScimException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testAClientCannotTouchAnotherClientsGroup(): void
    {
        // Arrange
        $app = new Support();
        $group = $this->group($app);
        $other = $app->clients->create('Beta', ['beta.com'], true, 'deactivate', false);

        // Act
        $get = fn () => $app->groupService->get($app->authenticator->authenticate($other['token']), $group->id);

        // Assert
        $this->expectException(ScimException::class);
        $get();
    }

    public function testDeletingRemovesTheGroupAndWhatWasGivenToIt(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->groupService->delete($app->client(), $group->id);

        // Assert
        $this->assertNull($app->data->groups->find($group->id));
        $this->assertNull($app->scimGroups->managedBy($group->id));
        $this->assertSame([], $app->data->users->groupNames($alice));
    }

    public function testDeactivatingAPersonKeepsTheirGroups(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $group = $this->group($app, 'Engineering', [$alice]);

        // Act
        $app->service->patch($app->client(), $alice, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        $this->assertSame([$alice], $app->scimGroups->members($group->id));
    }
}
