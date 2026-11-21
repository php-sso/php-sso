<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\SocialController;

use PhpSso\Auth\SocialController;
use PhpSso\Net\ClientIp;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Tests\unit\PhpSso\Auth\LoginController\Support as LoginSupport;
use Tests\unit\PhpSso\Auth\Social\SocialLogin\Support as SocialSupport;

#[CoversMethod(SocialController::class, 'start')]
#[CoversMethod(SocialController::class, 'callback')]
final class CallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return array{0: LoginSupport, 1: SocialSupport, 2: SocialController} */
    private function app(string $mfaPolicy = 'optional'): array
    {
        $login = new LoginSupport([], $mfaPolicy);
        $social = new SocialSupport([], $login->data);
        $_SESSION = ['csrf' => 'token'];

        return [$login, $social, new SocialController($social->social, $login->flow, $login->mfa, new ClientIp([]))];
    }

    /** @param array<string, string> $query */
    private static function returnRequest(array $query): ServerRequestInterface
    {
        return (new ServerRequest(['REMOTE_ADDR' => '203.0.113.9'], [], 'http://localhost/login/social/fake/callback', 'GET'))->withQueryParams($query);
    }

    /** Starts a round trip and returns the query the provider would send the browser back with. @return array<string, string> */
    private function comeBack(SocialSupport $social, string $intent = 'login', ?string $sub = null): array
    {
        $social->social->begin('fake', $intent, $sub);

        return ['state' => $social->provider->lastAuthorization['state'], 'code' => 'code-1'];
    }

    public function testItSendsTheBrowserToTheProvider(): void
    {
        // Arrange
        [, , $controller] = $this->app();

        // Act
        $response = $controller->start('fake');

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('https://idp.test/authorize?state=', $response->getHeaderLine('Location'));
    }

    public function testAProviderThatIsNotAvailableSendsThePersonBackToTheLoginPageWithAReason(): void
    {
        // Arrange
        [, , $controller] = $this->app();

        // Act
        $response = $controller->start('nope');

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('not available', $_SESSION['login_error']);
    }

    public function testAConnectedAccountSignsThePersonInAndKeepsTheReturnAddress(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $alice = $login->data->user('alice');
        $social->identities->link($alice, 'fake', 'sub-1', null);
        $_SESSION['return_to'] = '/authorize?client_id=demo';
        $query = $this->comeBack($social);

        // Act
        $response = $controller->callback(self::returnRequest($query), 'fake');

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/authorize?client_id=demo', $response->getHeaderLine('Location'));
        $this->assertSame($alice, $_SESSION['user']);
        $attempt = $login->attempts->recent(1)[0];
        $this->assertSame(['fake:alice@example.com', 1], [$attempt['username'], (int) $attempt['success']]);
    }

    public function testSomeoneWithTwoStepVerificationStillHasToGiveACode(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        [$user] = $login->enrolledUser();
        $social->identities->link($user['sub'], 'fake', 'sub-1', null);
        $query = $this->comeBack($social);

        // Act
        $response = $controller->callback(self::returnRequest($query), 'fake');

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login/mfa', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame('challenge', $login->flow->secondFactorPending()['step']);
    }

    public function testThePolicyStillMakesSomeoneSetItUpFirst(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app('everyone');
        $alice = $login->data->user('alice');
        $social->identities->link($alice, 'fake', 'sub-1', null);
        $query = $this->comeBack($social);

        // Act
        $response = $controller->callback(self::returnRequest($query), 'fake');

        // Assert
        $this->assertSame('/login/mfa/setup', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testAFailedSignInGoesBackToTheLoginPageWhichShowsTheReasonOnce(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $login->data->user('alice');
        $query = $this->comeBack($social);

        // Act
        $response = $controller->callback(self::returnRequest($query), 'fake');
        $page = (string) $login->controller->identifyForm()->getBody();
        $again = (string) $login->controller->identifyForm()->getBody();

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('No account here is connected', $page);
        $this->assertStringNotContainsString('No account here is connected', $again);
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testACallbackWithAForgedStateSignsNobodyIn(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $alice = $login->data->user('alice');
        $social->identities->link($alice, 'fake', 'sub-1', null);
        $this->comeBack($social);

        // Act
        $response = $controller->callback(self::returnRequest(['state' => 'forged', 'code' => 'c']), 'fake');

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame([], $social->provider->identifyCalls);
    }

    public function testTheSameCallbackCannotBeReplayed(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $alice = $login->data->user('alice');
        $social->identities->link($alice, 'fake', 'sub-1', null);
        $query = $this->comeBack($social);
        $controller->callback(self::returnRequest($query), 'fake');
        $_SESSION = ['csrf' => 'token'];

        // Act
        $replayed = $controller->callback(self::returnRequest($query), 'fake');

        // Assert
        $this->assertSame('/login', $replayed->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testConnectingAnAccountReturnsToTheAccountPageWithAMessage(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $alice = $login->data->user('alice');
        $query = $this->comeBack($social, 'link', $alice);

        // Act
        $response = $controller->callback(self::returnRequest($query), 'fake');

        // Assert
        $this->assertSame('/account#social', $response->getHeaderLine('Location'));
        $this->assertSame('success', $_SESSION['flash']['type']);
        $this->assertNotNull($social->identities->find('fake', 'sub-1'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testAFailureWhileConnectingReturnsToTheAccountPageNotTheLoginPage(): void
    {
        // Arrange
        [$login, $social, $controller] = $this->app();
        $alice = $login->data->user('alice');
        $social->social->begin('fake', 'link', $alice);
        $state = $social->provider->lastAuthorization['state'];

        // Act
        $response = $controller->callback(self::returnRequest(['state' => $state, 'error' => 'access_denied']), 'fake');

        // Assert
        $this->assertSame('/account#social', $response->getHeaderLine('Location'));
        $this->assertSame('error', $_SESSION['flash']['type']);
        $this->assertArrayNotHasKey('login_error', $_SESSION);
    }
}
