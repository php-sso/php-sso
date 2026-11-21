<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(LoginController::class, 'identify')]
final class IdentifyTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItSendsAKnownPersonToThePasswordStep(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'alice']));

        // Assert
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/login/password', $response->getHeaderLine('Location'));
        $this->assertSame(['identifier' => 'alice', 'sub' => $sub], $app->flow->pending());
    }

    public function testItAnswersAnUnknownPersonTheSameWay(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $known = $app->controller->identify(Support::post(['username' => 'alice']));

        // Act
        $unknown = $app->controller->identify(Support::post(['username' => 'ghost']));

        // Assert
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->getHeaderLine('Location'), $unknown->getHeaderLine('Location'));
        $this->assertSame(['identifier' => 'ghost', 'sub' => null], $app->flow->pending());
    }

    public function testItAsksAgainWhenNothingWasTyped(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->identify(Support::post(['username' => '   ']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($app->flow->pending());
    }

    public function testItRefusesARequestWithTheWrongCsrfToken(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'alice', '_csrf' => 'forged']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($app->flow->pending());
    }

    public function testItSignsInInOneStepWhenTheFormSendsAPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');

        // Act
        $response = $app->controller->identify(Support::post(['username' => 'alice', 'password' => 'secret-pass']));

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($sub, $_SESSION['user']);
    }
}
