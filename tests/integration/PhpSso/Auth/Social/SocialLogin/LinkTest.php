<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SocialLogin::class, 'finish')]
#[CoversMethod(SocialLogin::class, 'unlink')]
#[CoversMethod(SocialLogin::class, 'unlinkAny')]
#[CoversMethod(SocialLogin::class, 'overview')]
#[CoversMethod(SocialLogin::class, 'connectionsOf')]
#[CoversMethod(SocialLogin::class, 'providerStatus')]
final class LinkTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testASignedInPersonCanConnectAProviderAccount(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');

        // Act
        $result = $app->roundTrip(Support::identity('sub-1', 'unrelated@example.org', false), 'link', $alice);

        // Assert
        $this->assertSame('link', $result['intent']);
        $this->assertSame($alice, $app->identities->find('fake', 'sub-1')['user_sub']);
    }

    public function testConnectingTheSameAccountAgainChangesNothing(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');
        $app->roundTrip(null, 'link', $alice);

        // Act
        $app->roundTrip(null, 'link', $alice);

        // Assert
        $this->assertCount(1, $app->identities->forUser($alice));
    }

    public function testAProviderAccountOfSomeoneElseCannotBeTakenOver(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');
        $bob = $app->data->user('bob');
        $app->roundTrip(null, 'link', $alice);
        $this->expectException(SocialLoginException::class);

        // Act
        $app->roundTrip(null, 'link', $bob);

        // Assert (the exception above)
    }

    public function testConnectingNeedsAPersonWhoStillExists(): void
    {
        // Arrange
        $app = new Support();
        $this->expectException(SocialLoginException::class);

        // Act
        $app->roundTrip(null, 'link', 'a-user-that-does-not-exist');

        // Assert (the exception above)
    }

    public function testAPersonWithAPasswordCanDisconnectTheirOnlyProviderAccount(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->users->find($app->data->user('alice'));
        $app->identities->link($alice['sub'], 'fake', 'sub-1', null);

        // Act
        $problem = $app->social->unlink($alice, 'fake');

        // Assert
        $this->assertNull($problem);
        $this->assertSame([], $app->identities->forUser($alice['sub']));
    }

    public function testAPersonWithoutAPasswordCannotDisconnectTheirOnlyWayIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);
        $app->identities->link($sub, 'fake', 'sub-1', null);

        // Act
        $problem = $app->social->unlink($app->data->users->find($sub), 'fake');

        // Assert
        $this->assertStringContainsString('only way to sign in', (string) $problem);
        $this->assertCount(1, $app->identities->forUser($sub));
    }

    public function testAPersonWithoutAPasswordCanDisconnectOneOfSeveral(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);
        $app->identities->link($sub, 'fake', 'sub-1', null);
        $app->identities->link($sub, 'other', 'sub-2', null);

        // Act
        $problem = $app->social->unlink($app->data->users->find($sub), 'fake');

        // Assert
        $this->assertNull($problem);
    }

    public function testDisconnectingSomethingThatIsNotConnectedSaysSo(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->users->find($app->data->user('alice'));

        // Act
        $problem = $app->social->unlink($alice, 'fake');

        // Assert
        $this->assertStringContainsString('not connected', (string) $problem);
    }

    public function testAnAdministratorCanDisconnectEvenTheOnlyWayIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);
        $app->identities->link($sub, 'fake', 'sub-1', null);

        // Act
        $removed = $app->social->unlinkAny($sub, 'fake');

        // Assert
        $this->assertTrue($removed);
    }

    public function testTheOverviewOffersWhatIsNotConnectedAndSaysWhetherAnythingMayBeRemoved(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->users->find($app->data->user('alice'));

        // Act
        $before = $app->social->overview($alice);
        $app->identities->link($alice['sub'], 'fake', 'sub-1', 'alice@example.com');
        $after = $app->social->overview($alice);

        // Assert
        $this->assertSame([['key' => 'fake', 'label' => 'Fake']], $before['available']);
        $this->assertSame([], $after['available']);
        $this->assertSame(['Fake', 'alice@example.com'], [$after['linked'][0]['label'], $after['linked'][0]['email']]);
        $this->assertTrue($after['can_unlink']);
    }

    public function testTheProviderStatusListsTheAddressToRegister(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $status = $app->social->providerStatus();

        // Assert
        $this->assertSame([['key' => 'fake', 'label' => 'Fake', 'enabled' => true, 'redirect_uri' => 'https://sso.example.com/login/social/fake/callback']], $status);
    }
}
