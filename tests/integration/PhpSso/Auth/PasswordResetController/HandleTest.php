<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\PasswordResetController;

use Laminas\Diactoros\ServerRequest;
use PhpSso\Auth\PasswordResetController;
use PhpSso\Net\ClientIp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Tests\integration\PhpSso\Auth\PasswordReset\Support;

#[CoversMethod(PasswordResetController::class, 'handle')]
final class HandleTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = ['csrf' => 'token'];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function controller(Support $app): PasswordResetController
    {
        return new PasswordResetController($app->pages, $app->service, new ClientIp([]), 60);
    }

    /** @param array<string, string> $body */
    private static function request(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = new ServerRequest(['REMOTE_ADDR' => '203.0.113.5'], [], 'http://localhost' . $path, $method);

        return $method === 'POST' ? $request->withParsedBody($body + ['_csrf' => 'token']) : $request;
    }

    private function handle(PasswordResetController $controller, string $method, string $path, array $body = []): ?\Psr\Http\Message\ResponseInterface
    {
        return $controller->handle(self::request($method, $path, $body), $path);
    }

    public function testTheFormAsksForAUsernameOrAnEmailAddress(): void
    {
        // Arrange
        $controller = $this->controller(new Support());

        // Act
        $response = $this->handle($controller, 'GET', '/password/forgot');

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="identifier"', (string) $response->getBody());
        $this->assertStringContainsString('action="/password/forgot"', (string) $response->getBody());
    }

    public function testTheAnswerIsTheSameForAnAccountThatExistsAndOneThatDoesNot(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $controller = $this->controller($app);

        // Act
        $known = $this->handle($controller, 'POST', '/password/forgot', ['identifier' => 'alice']);
        $unknown = $this->handle($controller, 'POST', '/password/forgot', ['identifier' => 'nobody-at-all']);

        // Assert
        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertSame((string) $unknown->getBody(), (string) $known->getBody());
        $this->assertStringContainsString('If an account matches', (string) $known->getBody());
        $this->assertStringNotContainsString('alice', (string) $known->getBody());
    }

    public function testAFormThatCameWithoutTheTokenIsRefusedAndNothingIsDone(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $controller = $this->controller($app);
        $request = (new ServerRequest(['REMOTE_ADDR' => '203.0.113.5'], [], 'http://localhost/password/forgot', 'POST'))->withParsedBody(['identifier' => 'alice', '_csrf' => 'wrong']);

        // Act
        $response = $controller->handle($request, '/password/forgot');
        $app->answerSent();

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $app->mailer->sent);
    }

    public function testAnEmptyFormIsAskedAgain(): void
    {
        // Arrange
        $controller = $this->controller(new Support());

        // Act
        $response = $this->handle($controller, 'POST', '/password/forgot', ['identifier' => '  ']);

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Enter your username or your email address.', (string) $response->getBody());
    }

    public function testTheLinkMovesTheTokenIntoTheSessionAndLeavesTheAddressBarClean(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $token = $app->link();

        // Act
        $response = $this->handle($this->controller($app), 'GET', '/password/reset/' . $token);

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/password/reset', $response->getHeaderLine('Location'));
        $this->assertSame($token, $_SESSION['password_reset']);
    }

    public function testALinkThatDoesNotWorkSaysSoAndLeavesNothingInTheSession(): void
    {
        // Arrange
        $app = new Support();
        $_SESSION['password_reset'] = 'left over';

        // Act
        $response = $this->handle($this->controller($app), 'GET', '/password/reset/' . str_repeat('a', 43));
        $malformed = $this->handle($this->controller($app), 'GET', '/password/reset/short');

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('This link does not work', (string) $response->getBody());
        $this->assertSame(400, $malformed->getStatusCode());
        $this->assertArrayNotHasKey('password_reset', $_SESSION);
    }

    public function testThePasswordFormNeedsALinkThatWasOpened(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $controller = $this->controller($app);

        // Act
        $without = $this->handle($controller, 'GET', '/password/reset');
        $_SESSION['password_reset'] = $app->link();
        $with = $this->handle($controller, 'GET', '/password/reset');

        // Assert
        $this->assertSame(400, $without->getStatusCode());
        $this->assertSame(200, $with->getStatusCode());
        $this->assertStringContainsString('alice', (string) $with->getBody());
        $this->assertStringContainsString('name="new_password"', (string) $with->getBody());
    }

    public function testChoosingAPasswordChangesItEndsTheLinkAndSendsThePersonToSignIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $_SESSION['password_reset'] = $app->link();
        $controller = $this->controller($app);

        // Act
        $response = $this->handle($controller, 'POST', '/password/reset', ['new_password' => 'a-new-password-1', 'confirm_password' => 'a-new-password-1']);

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame('Your password was changed. Sign in with the new one.', $_SESSION['login_notice']);
        $this->assertArrayNotHasKey('password_reset', $_SESSION);
        $this->assertTrue(password_verify('a-new-password-1', $app->data->users->find($sub)['password_hash']));
    }

    public function testAMistakeShowsTheFormAgainAndKeepsTheLink(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $_SESSION['password_reset'] = $app->link();
        $controller = $this->controller($app);

        // Act
        $response = $this->handle($controller, 'POST', '/password/reset', ['new_password' => 'short', 'confirm_password' => 'short']);

        // Assert
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Use at least 8 characters.', (string) $response->getBody());
        $this->assertArrayHasKey('password_reset', $_SESSION);
    }

    public function testTheSameLinkCannotChooseAPasswordTwice(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $token = $app->link();
        $controller = $this->controller($app);
        $_SESSION['password_reset'] = $token;
        $this->handle($controller, 'POST', '/password/reset', ['new_password' => 'a-new-password-1', 'confirm_password' => 'a-new-password-1']);

        // Act
        $again = $this->handle($controller, 'GET', '/password/reset/' . $token);

        // Assert
        $this->assertSame(400, $again->getStatusCode());
    }

    public function testAnyOtherAddressIsNotForThisController(): void
    {
        // Arrange
        $controller = $this->controller(new Support());

        // Act
        $other = $this->handle($controller, 'GET', '/login');
        $wrongMethod = $this->handle($controller, 'DELETE', '/password/forgot');

        // Assert
        $this->assertNull($other);
        $this->assertNull($wrongMethod);
    }
}
