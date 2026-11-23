<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\RequiredActionController;

use PhpSso\Auth\RequiredActionController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Auth\LoginController\Support;

#[CoversMethod(RequiredActionController::class, 'form')]
final class FormTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testSomeoneWithNothingPendingGoesToTheStart(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->requiredController->form();

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testThePasswordPageIsShownToSomeoneWhoMustChooseOne(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $app->flow->beginActions($sub, 'alice');

        // Act
        $response = $app->requiredController->form();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('name="new_password"', (string) $response->getBody());
        $this->assertStringContainsString('action="/login/required"', (string) $response->getBody());
    }

    public function testWhenNothingAppliesAnyMoreThePersonIsSignedIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $_SESSION['return_to'] = '/account';
        $app->flow->beginActions($sub, 'alice'); // not flagged: done elsewhere meanwhile

        // Act
        $response = $app->requiredController->form();

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
    }
}
