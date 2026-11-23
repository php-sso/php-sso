<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\ScimServer\ScimGroupService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimGroupService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\ScimServer\Support;

#[CoversMethod(ScimGroupService::class, 'create')]
#[CoversMethod(ScimGroupService::class, 'representation')]
final class CreateTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function payload(string $name = 'Engineering', array $extra = []): array
    {
        return $extra + ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'], 'displayName' => $name, 'externalId' => 'grp-' . $name];
    }

    private function person(Support $app, string $userName): string
    {
        return $app->service->create($app->client(), Support::payload($userName))->sub;
    }

    public function testItCreatesAGroupAppsCanSeeUnderItsName(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $row = $app->groupService->create($app->client(), self::payload('Engineering'));

        // Assert
        $this->assertSame(['Engineering', 'Engineering', 'grp-Engineering'], [$row->name, $row->displayName, $row->externalId]);
        $this->assertSame('Provisioned by Acme Okta', $row->description);
        $this->assertNotNull($app->data->groups->findByName('Engineering'));
    }

    public function testSpacesAndOtherCharactersBecomeDashesButTheProvidersNameIsKept(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $row = $app->groupService->create($app->client(), self::payload('All Employees / EU'));

        // Assert
        $this->assertSame('All-Employees-EU', $row->name);
        $this->assertSame('All Employees / EU', $row->displayName);
        $this->assertSame('All Employees / EU', $app->groupService->representation($row)['displayName']);
    }

    public function testTheClientsPrefixKeepsItsGroupsApartFromYours(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com'], 'email', 'acme:');

        // Act
        $row = $app->groupService->create($app->client(), self::payload('Engineering'));

        // Assert
        $this->assertSame('acme:Engineering', $row->name);
        $this->assertSame('Engineering', $app->groupService->representation($row)['displayName']);
    }

    public function testItAddsOnlyPeopleTheClientManages(): void
    {
        // Arrange
        $app = new Support();
        $alice = $this->person($app, 'alice@acme.com');
        $bob = $this->person($app, 'bob@acme.com');

        // Act
        $row = $app->groupService->create($app->client(), self::payload('Engineering', ['members' => [['value' => $alice], ['value' => $bob]]]));

        // Assert
        $members = array_column($app->groupService->representation($row)['members'], 'value');
        $this->assertEqualsCanonicalizing([$alice, $bob], $members);
    }

    public function testSomeoneItDoesNotManageCannotBeAdded(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->data->user('stranger');

        // Act
        $create = fn () => $app->groupService->create($app->client(), self::payload('Engineering', ['members' => [['value' => $stranger]]]));

        // Assert
        try {
            $create();
            $this->fail('A stranger was added to a group.');
        } catch (ScimException $e) {
            $this->assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
            $this->assertStringContainsString('not a person this client manages', $e->getMessage());
        }
        $this->assertNull($app->data->groups->findByName('Engineering'));
    }

    public function testAnAdministratorCannotBePulledInEitherUnlessTheClientManagesThem(): void
    {
        // Arrange
        $app = new Support();
        $root = $app->data->users->create(['username' => 'root', 'password' => 'secret-pass', 'name' => 'Root', 'email' => 'root@acme.com', 'email_verified' => true, 'is_admin' => true]);

        // Act
        $create = fn () => $app->groupService->create($app->client(), self::payload('Engineering', ['members' => [['value' => $root]]]));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testAGroupWithTheNameOfOneYouMadeIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $mine = $app->data->groups->create('admins', 'Mine');

        // Act
        $create = fn () => $app->groupService->create($app->client(), self::payload('Admins'));

        // Assert
        try {
            $create();
            $this->fail('A group of yours was taken over.');
        } catch (ScimException $e) {
            $this->assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
        $this->assertSame([], $app->scimGroups->members($mine));
    }

    public function testWhenAllowedItTakesOverAGroupThatExists(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com'], 'email', '', true);
        $mine = $app->data->groups->create('Engineering', 'Mine');
        $alice = $this->person($app, 'alice@acme.com');

        // Act
        $row = $app->groupService->create($app->client(), self::payload('Engineering', ['members' => [['value' => $alice]]]));

        // Assert
        $this->assertSame($mine, $row->id);
        $this->assertSame($app->clientId, (int) $app->scimGroups->managedBy($mine)['id']);
        $this->assertSame([$alice], $app->scimGroups->members($mine));
    }

    public function testAGroupAnotherClientManagesIsNeverTakenOver(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com'], 'email', '', true);
        $app->groupService->create($app->client(), self::payload('Engineering'));
        $other = $app->clients->create('Beta', ['beta.com'], true, 'deactivate', false, '', true);

        // Act
        $create = fn () => $app->groupService->create($app->authenticator->authenticate($other['token']), self::payload('Engineering'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testTheSameDisplayNameTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->groupService->create($app->client(), self::payload('Engineering'));

        // Act
        $again = fn () => $app->groupService->create($app->client(), self::payload('engineering', ['externalId' => 'other']));

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('already exists');
        $again();
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'only symbols' => ['***'];
        yield 'too long once cleaned' => [str_repeat('x', 65)];
        yield 'nothing' => [''];
    }

    #[DataProvider('badNames')]
    public function testANameThatCannotBeAGroupNameIsRefused(string $name): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->groupService->create($app->client(), self::payload($name));

        // Assert
        try {
            $create();
            $this->fail('A bad name was accepted.');
        } catch (ScimException $e) {
            $this->assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
    }

    public function testThePrefixCountsTowardsTheLengthLimit(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com'], 'email', 'acme:');

        // Act
        $create = fn () => $app->groupService->create($app->client(), self::payload(str_repeat('x', 60)));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }
}
