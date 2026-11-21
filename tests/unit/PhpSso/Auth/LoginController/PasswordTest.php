<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(LoginController::class, 'password')]
final class PasswordTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItSignsThePersonInWithTheRightPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $_SESSION['return_to'] = '/account';
        $app->flow->begin('alice', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertSame(1, (int) $app->attempts->recent(1)[0]['success']);
    }

    public function testItRejectsAWrongPasswordAndRecordsTheFailure(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'wrong']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid credentials', (string) $response->getBody());
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame(1, count($app->attempts->failureTimesForIdentifier('alice', $sub, 0)));
    }

    public function testItRejectsAnUnknownIdentifierWithTheSameAnswerAsAWrongPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);
        $wrong = $app->controller->password(Support::post(['password' => 'wrong']));
        $app->flow->begin('ghost', null);

        // Act
        $unknown = $app->controller->password(Support::post(['password' => 'wrong']));

        // Assert
        $this->assertSame($wrong->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(1, count($app->attempts->failureTimesForIdentifier('ghost', null, 0)));
    }

    public function testItSendsThePersonBackWhenNobodyIsPending(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testItStopsAfterTooManyFailuresEvenWithTheRightPassword(): void
    {
        // Arrange
        $app = new Support(['per_identifier' => 2, 'per_ip' => 100, 'window_seconds' => 900]);
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);
        $app->controller->password(Support::post(['password' => 'wrong']));
        $app->controller->password(Support::post(['password' => 'wrong']));

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(898, (int) $response->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(900, (int) $response->getHeaderLine('Retry-After'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testItStopsAnAddressThatFailsForManyIdentifiers(): void
    {
        // Arrange
        $app = new Support(['per_identifier' => 100, 'per_ip' => 2, 'window_seconds' => 900]);
        foreach (['a', 'b'] as $name) {
            $app->flow->begin($name, null);
            $app->controller->password(Support::post(['password' => 'wrong']));
        }
        $app->flow->begin('c', null);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'wrong']));

        // Assert
        $this->assertSame(429, $response->getStatusCode());
    }

    public function testAPersonWithoutALocalPasswordCannotSignInWithAnyPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);
        $app->flow->begin('scim', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => '']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testARightPasswordLeadsToTheRequiredStepForSomeoneFlagged(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $app->flow->begin('alice', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame('/login/required', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testItRefusesALockedAccountEvenWithTheRightPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setLocked($sub, true);
        $app->flow->begin('alice', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('This account is locked', (string) $response->getBody());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }
}
