<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginController;

use PhpSso\Auth\LoginController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(LoginController::class, 'password')]
final class SecondFactorTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testARightPasswordIsNotEnoughForSomeoneWithTheApp(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->begin('alice', $user['sub']);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame('/login/mfa', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame('challenge', $app->flow->secondFactorPending()['step']);
    }

    public function testThePolicyMakesSomeoneWithoutTheAppSetItUpFirst(): void
    {
        // Arrange
        $app = new Support([], 'everyone');
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame('/login/mfa/setup', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame('enroll', $app->flow->secondFactorPending()['step']);
    }

    public function testAnAdministratorPolicyLeavesOrdinaryPeopleAlone(): void
    {
        // Arrange
        $app = new Support([], 'admins');
        $sub = $app->data->user('alice');
        $app->flow->begin('alice', $sub);

        // Act
        $app->controller->password(Support::post(['password' => 'secret-pass']));

        // Assert
        $this->assertSame($sub, $_SESSION['user']);
    }

    public function testAWrongPasswordNeverReachesTheSecondFactor(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $app->flow->begin('alice', $user['sub']);

        // Act
        $response = $app->controller->password(Support::post(['password' => 'wrong']));

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertNull($app->flow->secondFactorPending());
    }
}
