<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/** Who a provider account is: connected before, or matched by email, or created, or nobody. */
#[CoversMethod(SocialLogin::class, 'finish')]
final class ResolveTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function refused(Support $app, ?\PhpSso\Auth\Social\ExternalIdentity $identity = null): bool
    {
        try {
            $app->roundTrip($identity);
        } catch (SocialLoginException) {
            return true;
        }

        return false;
    }

    public function testAProviderAccountThatWasConnectedBeforeSignsInItsPerson(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');
        $app->identities->link($alice, 'fake', 'sub-1', null);

        // Act
        $result = $app->roundTrip(Support::identity('sub-1', 'someone-else@example.com', false));

        // Assert
        $this->assertSame($alice, $result['user']['sub']);
        $this->assertNotNull($app->identities->find('fake', 'sub-1')['last_login_at']);
    }

    public function testAnUnknownProviderAccountGetsNowhereWhenBothWaysInAreOff(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $refused = $this->refused($app);

        // Assert
        $this->assertTrue($refused);
        $this->assertCount(1, $app->data->users->all());
    }

    public function testAVerifiedEmailConnectsToTheMatchingPersonWhenThePolicyAllowsIt(): void
    {
        // Arrange
        $app = new Support(['link_by_verified_email' => true]);
        $alice = $app->data->user('alice');

        // Act
        $result = $app->roundTrip(Support::identity('sub-1', 'Alice@Example.com'));

        // Assert
        $this->assertSame($alice, $result['user']['sub']);
        $this->assertSame($alice, $app->identities->find('fake', 'sub-1')['user_sub']);
    }

    public function testAnEmailTheProviderDidNotVerifyNeverConnects(): void
    {
        // Arrange
        $app = new Support(['link_by_verified_email' => true]);
        $app->data->user('alice');

        // Act
        $refused = $this->refused($app, Support::identity('sub-1', 'alice@example.com', false));

        // Assert
        $this->assertTrue($refused);
        $this->assertNull($app->identities->find('fake', 'sub-1'));
    }

    public function testAnEmailWeHaveNotVerifiedEitherNeverConnects(): void
    {
        // Arrange
        $app = new Support(['link_by_verified_email' => true]);
        $alice = $app->data->user('alice');
        $app->data->db->pdo->prepare('UPDATE users SET email_verified = 0 WHERE sub = ?')->execute([$alice]);

        // Act
        $refused = $this->refused($app);

        // Assert
        $this->assertTrue($refused);
    }

    public function testAPersonWhoAlreadyHasADifferentAccountAtThatProviderIsNotConnectedAgain(): void
    {
        // Arrange
        $app = new Support(['link_by_verified_email' => true]);
        $alice = $app->data->user('alice');
        $app->identities->link($alice, 'fake', 'old-subject', null);

        // Act
        $refused = $this->refused($app, Support::identity('new-subject'));

        // Assert
        $this->assertTrue($refused);
        $this->assertNull($app->identities->find('fake', 'new-subject'));
    }

    public function testAnEmailOutsideTheAllowedDomainsNeverConnectsOrCreates(): void
    {
        // Arrange
        $app = new Support(['link_by_verified_email' => true, 'create_users' => true, 'allowed_domains' => ['corp.example']]);
        $app->data->user('alice');

        // Act
        $refused = $this->refused($app);

        // Assert
        $this->assertTrue($refused);
        $this->assertCount(1, $app->data->users->all());
    }

    public function testNewPeopleAreCreatedWithoutPasswordOrAdminRightsWhenThePolicyAllowsIt(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);

        // Act
        $result = $app->roundTrip(Support::identity('sub-9', 'Bob.Builder@Example.com', true, 'Bob Builder'));

        // Assert
        $user = $app->data->users->find($result['user']['sub']);
        $this->assertSame(['bob.builder', 'Bob Builder', 'bob.builder@example.com', 1, 0], [$user['username'], $user['name'], $user['email'], (int) $user['email_verified'], (int) $user['is_admin']]);
        $this->assertNull($user['password_hash']);
        $this->assertSame($user['sub'], $app->identities->find('fake', 'sub-9')['user_sub']);
    }

    public function testTheSameProviderAccountSignsInTheCreatedPersonNextTime(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);
        $first = $app->roundTrip(Support::identity('sub-9', 'bob@example.com'));

        // Act
        $second = $app->roundTrip(Support::identity('sub-9', 'bob@example.com'));

        // Assert
        $this->assertSame($first['user']['sub'], $second['user']['sub']);
        $this->assertCount(1, $app->data->users->all());
    }

    public function testAUsernameThatIsTakenGetsANumber(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);
        $app->data->users->create(['username' => 'bob', 'password' => 'secret-pass', 'name' => 'Other Bob', 'email' => 'other-bob@example.com', 'email_verified' => true, 'is_admin' => false]);

        // Act
        $result = $app->roundTrip(Support::identity('sub-9', 'bob@example.com'));

        // Assert
        $this->assertSame('bob2', $result['user']['username']);
    }

    public function testNobodyIsCreatedForAnEmailThatBelongsToSomeoneAlready(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);
        $app->data->user('alice');

        // Act
        $refused = $this->refused($app);

        // Assert
        $this->assertTrue($refused);
        $this->assertCount(1, $app->data->users->all());
    }

    public function testNobodyIsCreatedFromAnEmailTheProviderDidNotVerify(): void
    {
        // Arrange
        $app = new Support(['create_users' => true]);

        // Act
        $refused = $this->refused($app, Support::identity('sub-9', 'bob@example.com', false));

        // Assert
        $this->assertTrue($refused);
        $this->assertSame([], $app->data->users->all());
    }

    public function testAnIdentityWithNoEmailAtAllIsNotMatchedOrCreated(): void
    {
        // Arrange
        $app = new Support(['create_users' => true, 'link_by_verified_email' => true]);

        // Act
        $refused = $this->refused($app, Support::identity('sub-9', null, false));

        // Assert
        $this->assertTrue($refused);
    }
}
