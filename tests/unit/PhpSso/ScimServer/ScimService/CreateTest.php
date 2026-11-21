<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\ScimServer\ScimService;

use PhpSso\ScimServer\ScimException;
use PhpSso\ScimServer\ScimService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\ScimServer\Support;

#[CoversMethod(ScimService::class, 'create')]
#[CoversMethod(ScimService::class, 'representation')]
final class CreateTest extends TestCase
{
    public function testItCreatesAPersonWithoutAPasswordOrAdminRights(): void
    {
        // Arrange
        $app = new Support();

        $user = $app->service->create($app->client(), Support::payload('Alice@Acme.com'));

        // Assert
        $account = $app->data->users->find($user->sub);
        $this->assertNull($account['password_hash']);
        $this->assertSame([0, 1, 'alice@acme.com', 'Alice Acme', 'alice'], [(int) $account['is_admin'], (int) $account['email_verified'], $user->email, $user->name, $account['username']]);
        $this->assertSame(['Alice@Acme.com', 'ext-Alice@Acme.com', 'Alice', 'Acme'], [$user->userName, $user->externalId, $user->givenName, $user->familyName]);
        $this->assertTrue($user->active);
    }

    public function testTheRepresentationIsWhatProvidersExpect(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $scim = $app->service->representation($user);

        // Assert
        $this->assertSame($user->sub, $scim['id']);
        $this->assertSame('alice@acme.com', $scim['userName']);
        $this->assertSame(['formatted' => 'Alice Acme', 'givenName' => 'Alice', 'familyName' => 'Acme'], $scim['name']);
        $this->assertSame([['value' => 'alice@acme.com', 'type' => 'work', 'primary' => true]], $scim['emails']);
        $this->assertTrue($scim['active']);
        $this->assertSame('https://sso.test/scim/v2/Users/' . $user->sub, $scim['meta']['location']);
    }

    public function testActiveFalseCreatesALockedAccount(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com', ['active' => false]));

        // Assert
        $this->assertTrue(\PhpSso\Store\Users::isLocked($app->data->users->find($user->sub)));
        $this->assertFalse($user->active);
        $this->assertFalse($app->service->representation($user)['active']);
    }

    public function testAnAddressOutsideTheTokensDomainsIsRefused(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('ceo@other-company.com'));

        // Assert
        $this->expectException(ScimException::class);
        $this->expectExceptionMessage('outside the domains');
        $create();
    }

    public function testASubdomainIsNotTheDomain(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('x@evil.acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testTheSameUserNameTwiceIsAConflict(): void
    {
        // Arrange
        $app = new Support();
        $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Act
        $again = fn () => $app->service->create($app->client(), Support::payload('alice@acme.com', ['emails' => [['value' => 'other@acme.com']]]));

        // Assert
        try {
            $again();
            $this->fail('A duplicate was accepted.');
        } catch (ScimException $e) {
            $this->assertSame([409, 'uniqueness'], [$e->status, $e->scimType]);
        }
    }

    public function testAnEmailAddressAnotherAccountUsesIsAConflictByDefault(): void
    {
        // Arrange
        $app = new Support();
        $app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => true, 'is_admin' => false]);

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            $this->fail('An account with that address was taken over.');
        } catch (ScimException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testWhenAllowedItTakesOverAnExistingAccountWithTheSameEmail(): void
    {
        // Arrange
        $app = new Support('deactivate', true);
        $sub = $app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => false, 'is_admin' => false]);

        // Act
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        $this->assertSame($sub, $user->sub);
        $this->assertNotNull($app->data->users->find($sub)['password_hash']);
        $this->assertSame($app->clientId, (int) $app->scimUsers->managedBy($sub)['id']);
    }

    public function testAnAdministratorIsNeverTakenOver(): void
    {
        // Arrange
        $app = new Support('deactivate', true);
        $app->data->users->create(['username' => 'root', 'password' => 'secret-pass', 'name' => 'Root', 'email' => 'root@acme.com', 'email_verified' => true, 'is_admin' => true]);

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('root@acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testAnAccountAnotherClientManagesIsNotTakenOver(): void
    {
        // Arrange
        $app = new Support('deactivate', true);
        $other = $app->clients->create('Beta', ['acme.com'], true, 'deactivate', true);
        $sub = $app->data->users->create(['username' => 'alice', 'password' => null, 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => true, 'is_admin' => false]);
        $app->scimUsers->attach($other['id'], $sub, 'alice@acme.com', null, null, null);

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        $this->expectException(ScimException::class);
        $create();
    }

    public function testTheSignInUsernameComesFromTheAddressAndIsMadeUnique(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        $this->assertSame('alice2', $app->data->users->find($user->sub)['username']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'no userName' => [['userName' => '']];
        yield 'no email anywhere' => [['userName' => 'alice', 'emails' => []]];
        yield 'an email that is not one' => [['userName' => 'alice', 'emails' => [['value' => 'not-an-email']]]];
        yield 'active that is not a boolean' => [['active' => 'maybe']];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testSomethingMalformedIsRefused(array $override): void
    {
        // Arrange
        $app = new Support();

        // Act
        $create = fn () => $app->service->create($app->client(), $override + Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            $this->fail('A malformed request was accepted.');
        } catch (ScimException $e) {
            $this->assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
    }

    public function testANewPersonOutsideTheAllowedEmailDomainsIsRefused(): void
    {
        // Arrange
        $app = new Support(allowedDomains: ['other.com']);

        // Act
        $create = fn () => $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        try {
            $create();
            $this->fail('A person outside the allowed domains was created.');
        } catch (ScimException $e) {
            $this->assertSame([400, 'invalidValue'], [$e->status, $e->scimType]);
        }
        $this->assertNull($app->data->users->findByEmail('alice@acme.com'));
    }

    public function testTheAllowedEmailDomainsDoNotStopAClientTakingOverAnExistingAccount(): void
    {
        // Arrange
        $app = new Support('deactivate', true, ['acme.com'], 'email', '', false, ['other.com']);
        $sub = $app->data->users->create(['username' => 'alice', 'password' => 'secret-pass', 'name' => 'Alice', 'email' => 'alice@acme.com', 'email_verified' => true, 'is_admin' => false]);

        // Act
        $user = $app->service->create($app->client(), Support::payload('alice@acme.com'));

        // Assert
        $this->assertSame($sub, $user->sub);
    }
}
