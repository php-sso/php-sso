<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\ScimServer\ScimService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimService;
use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\ScimServer\Support;
use PhpSso\Store\UserState;

#[CoversMethod(ScimService::class, 'replace')]
#[CoversMethod(ScimService::class, 'patch')]
#[CoversMethod(ScimService::class, 'delete')]
#[CoversMethod(ScimService::class, 'get')]
final class ChangeTest extends TestCase
{
    public function testDeactivatingLocksTheAccountAndEndsItsSessions(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $session = $app->sessions->start($user->sub, '203.0.113.9', 'agent');

        // Act
        $result = $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        $this->assertFalse($result['user']->active);
        $this->assertTrue(UserState::isLocked($app->data->users->find($user->sub)));
        $this->assertFalse($app->sessions->touch($session, $user->sub));
    }

    public function testReactivatingLetsThemBackIn(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com', ['active' => false]));

        // Act
        $result = $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => 'True']]);

        // Assert
        $this->assertTrue($result['user']->active);
        $this->assertFalse(UserState::isLocked($app->data->users->find($user->sub)));
    }

    public function testPatchChangesOnlyWhatItNames(): void
    {
        // Arrange
        $app = new Support();
        $created = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client(), $created->sub, [['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Brown']]);

        // Assert
        $user = $result['user'];
        $this->assertSame(['Alice Brown', 'Alice', 'Brown', 'alice@acme.com', 'ext-alice@acme.com'], [$user->name, $user->givenName, $user->familyName, $user->email, $user->externalId]);
    }

    public function testPatchCanChangeTheEmailWithinTheDomains(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $result = $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'emails[type eq "work"].value', 'value' => 'Alice.Brown@acme.com']]);

        // Assert
        $this->assertSame('alice.brown@acme.com', $result['user']->email);
    }

    public function testPatchCannotMoveAnAddressOutOfTheDomains(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $move = fn () => $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'emails', 'value' => 'alice@other-company.com']]);

        // Assert
        $this->expectException(ScimException::class);
        $move();
    }

    public function testReplaceSetsTheWholeProfile(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $replaced = $app->service->replace($app->client(), $user->sub, Support::payload('alice@acme.com', ['name' => ['formatted' => 'Alice the Great'], 'externalId' => 'new-ext', 'active' => false]));

        // Assert
        $this->assertSame(['Alice the Great', 'new-ext', null, false], [$replaced->name, $replaced->externalId, $replaced->givenName, $replaced->active]);
    }

    public function testAClientCannotTouchSomeoneItDidNotCreate(): void
    {
        // Arrange
        $app = new Support();
        $stranger = $app->data->user('stranger');

        // Act
        $touch = fn () => $app->service->patch($app->client(), $stranger, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        try {
            $touch();
            $this->fail('A stranger was changed.');
        } catch (ScimException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertFalse(UserState::isLocked($app->data->users->find($stranger)));
    }

    public function testAClientCannotTouchAnotherClientsPeople(): void
    {
        // Arrange
        $app = new Support();
        $other = $app->clients->create('Beta', ['acme.com'], true, 'deactivate', false);
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $otherClient = $app->authenticator->authenticate($other['token']);

        // Act
        $get = fn () => $app->service->get($otherClient, $user->sub);

        // Assert
        $this->expectException(ScimException::class);
        $get();
    }

    public function testAnAdministratorCannotBeChangedEvenWhenItWasCreatedHere(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $app->data->users->update($user->sub, ['username' => $app->data->users->find($user->sub)['username'], 'name' => $user->name, 'email' => $user->email, 'email_verified' => true, 'is_admin' => true, 'password' => '']);

        // Act
        $deactivate = fn () => $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        // Assert
        try {
            $deactivate();
            $this->fail('An administrator was deactivated.');
        } catch (ScimException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testAUserNameAnotherPersonHasIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $bob = $app->service->create($app->client(), Support::payload('bob@acme.com'));

        // Act
        $rename = fn () => $app->service->patch($app->client(), $bob->sub, [['op' => 'replace', 'path' => 'userName', 'value' => 'ALICE@acme.com']]);

        // Assert
        try {
            $rename();
            $this->fail('A duplicate userName was accepted.');
        } catch (ScimException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testDeleteDeactivatesByDefaultAndKeepsTheRecord(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $app->service->delete($app->client(), $user->sub);

        // Assert
        $kept = $app->data->users->find($user->sub);
        $this->assertNotNull($kept);
        $this->assertTrue(UserState::isLocked($kept));
        $this->assertNotNull($app->scimUsers->managedBy($user->sub));
    }

    public function testDeleteRemovesTheAccountWhenTheClientIsSetToDoThat(): void
    {
        // Arrange
        $app = new Support('delete');
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $session = $app->sessions->start($user->sub, '203.0.113.9', 'agent');

        // Act
        $app->service->delete($app->client(), $user->sub);

        // Assert
        $this->assertNull($app->data->users->find($user->sub));
        $this->assertNull($app->scimUsers->managedBy($user->sub));
        $this->assertFalse($app->sessions->touch($session, $user->sub));
    }

    public function testPatchCannotMoveAnAddressToADomainThatIsNotAllowed(): void
    {
        // Arrange
        $app = new Support('deactivate', false, ['acme.com', 'acme.org']);
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $app->policy->save(['link_by_verified_email' => false, 'create_users' => false, 'allowed_domains' => 'acme.com']);

        // Act
        $move = fn () => $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'emails', 'value' => 'alice@acme.org']]);

        // Assert
        try {
            $move();
            $this->fail('An address was moved to a domain that is not allowed.');
        } catch (ScimException $e) {
            $this->assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
        $this->assertSame('alice@acme.com', $app->data->users->find($user->sub)['email']);
    }

    public function testSomeoneWhoWasThereBeforeTheDomainsWereRestrictedCanStillBeChanged(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));
        $app->policy->save(['link_by_verified_email' => false, 'create_users' => false, 'allowed_domains' => 'other.com']);

        // Act
        $result = $app->service->patch($app->client(), $user->sub, [['op' => 'replace', 'path' => 'name.familyName', 'value' => 'Brown']]);

        // Assert
        $this->assertSame('Alice Brown', $result['user']->name);
    }
}
