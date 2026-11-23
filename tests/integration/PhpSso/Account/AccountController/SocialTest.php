<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Account\AccountController;

use Tests\integration\PhpSso\Account\AccountController\Support;

use PhpSso\Account\AccountController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AccountController::class, 'handle')]
final class SocialTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheAccountPageOffersToConnectAnAvailableProvider(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $body = (string) $app->controller->handle(Support::get('/account'), $user)->getBody();

        // Assert
        $this->assertStringContainsString('action="/account/social/fake/link"', $body);
        $this->assertStringContainsString('Connect Fake', $body);
    }

    public function testTheAccountPageListsAConnectedAccountWithADisconnectButton(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->social->identities->link($user['sub'], 'fake', 'sub-1', 'alice@example.com');

        // Act
        $body = (string) $app->controller->handle(Support::get('/account'), $user)->getBody();

        // Assert
        $this->assertStringContainsString('alice@example.com', $body);
        $this->assertStringContainsString('action="/account/social/fake/unlink"', $body);
        $this->assertStringNotContainsString('Connect Fake', $body);
    }

    public function testConnectingSendsThePersonToTheProviderAndRemembersWhoItIsFor(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::post('/account/social/fake/link'), $user);

        // Assert
        $this->assertStringStartsWith('https://idp.test/authorize?', $response->getHeaderLine('Location'));
        $this->assertSame(['link', $user['sub']], [$_SESSION['social']['intent'], $_SESSION['social']['sub']]);
    }

    public function testConnectingAProviderThatIsNotAvailableSaysSo(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::post('/account/social/nope/link'), $user);

        // Assert
        $this->assertSame('/account#social', $response->getHeaderLine('Location'));
        $this->assertSame('error', $_SESSION['flash']['type']);
    }

    public function testDisconnectingRemovesTheConnection(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->social->identities->link($user['sub'], 'fake', 'sub-1', null);

        // Act
        $response = $app->controller->handle(Support::post('/account/social/fake/unlink'), $user);

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame('success', $_SESSION['flash']['type']);
        $this->assertNull($app->social->identities->find('fake', 'sub-1'));
    }

    public function testTheOnlyWayToSignInCannotBeDisconnected(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->login->data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);
        $user = $app->login->data->users->find($sub);
        $app->social->identities->link($sub, 'fake', 'sub-1', null);

        // Act
        $response = $app->controller->handle(Support::post('/account/social/fake/unlink'), $user);
        $page = (string) $app->controller->handle(Support::get('/account'), $user)->getBody();

        // Assert
        $this->assertSame('/account#social', $response->getHeaderLine('Location'));
        $this->assertNotNull($app->social->identities->find('fake', 'sub-1'));
        $this->assertStringNotContainsString('action="/account/social/fake/unlink"', $page);
        $this->assertStringContainsString('only way to sign in', $page);
    }
}
