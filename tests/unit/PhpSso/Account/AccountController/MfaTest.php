<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Account\AccountController;

use PhpSso\Account\AccountController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support as LoginSupport;

#[CoversMethod(AccountController::class, 'handle')]
final class MfaTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheAccountPageOffersToSetItUpWhenItIsOff(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::get('/account'), $user);

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('href="/account/mfa/setup"', (string) $response->getBody());
    }

    public function testAPersonCanSetItUpWithACodeFromTheApp(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->controller->handle(Support::get('/account/mfa/setup'), $user);
        $secret = $_SESSION['mfa_setup'];

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/setup', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Your recovery codes', (string) $response->getBody());
        $this->assertTrue($app->login->mfa->isEnrolled($user['sub']));
        $this->assertArrayNotHasKey('mfa_setup', $_SESSION);
    }

    public function testAWrongCodeDuringSetupLeavesItOff(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->controller->handle(Support::get('/account/mfa/setup'), $user);

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/setup', ['code' => '000000']), $user);

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($app->login->mfa->isEnrolled($user['sub']));
    }

    public function testItCanBeTurnedOffWithACodeFromTheApp(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->login->enrolledUser();

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/disable', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertFalse($app->login->mfa->isEnrolled($user['sub']));
    }

    public function testItStaysOnWhenTheCodeIsWrong(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->login->enrolledUser();

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/disable', ['code' => '000000']), $user);

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($app->login->mfa->isEnrolled($user['sub']));
    }

    public function testItCannotBeTurnedOffWhenThePolicyRequiresIt(): void
    {
        // Arrange
        $app = new Support('everyone');
        [$user, $secret] = $app->login->enrolledUser();

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/disable', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($app->login->mfa->isEnrolled($user['sub']));
    }

    public function testNewRecoveryCodesNeedACodeAndReplaceTheOldOnes(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret, $old] = $app->login->enrolledUser();

        // Act
        $wrong = $app->controller->handle(Support::post('/account/mfa/recovery', ['code' => '000000']), $user);
        $right = $app->controller->handle(Support::post('/account/mfa/recovery', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame(401, $wrong->getStatusCode());
        $this->assertSame(200, $right->getStatusCode());
        $this->assertStringContainsString('Your recovery codes', (string) $right->getBody());
        $this->assertFalse($app->login->mfa->verify($user['sub'], $old[0]));
    }

    public function testManyWrongCodesLockTheAccountOutOfTheseActionsToo(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->login->enrolledUser();
        foreach (range(1, 5) as $i) {
            $app->controller->handle(Support::post('/account/mfa/disable', ['code' => '00000' . $i]), $user);
        }

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/disable', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($app->login->mfa->isEnrolled($user['sub']));
    }

    public function testTheAccountPageShowsItIsOnWithARecoveryCodeCountAndATurnOffButton(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->login->enrolledUser();

        // Act
        $body = (string) $app->controller->handle(Support::get('/account'), $user)->getBody();

        // Assert
        $this->assertStringContainsString('You have 8 unused recovery codes', $body);
        $this->assertStringContainsString('/account/mfa/disable', $body);
    }

    public function testTheAccountPageHidesTurnOffWhenThePolicyRequiresIt(): void
    {
        // Arrange
        $app = new Support('everyone');
        [$user] = $app->login->enrolledUser();

        // Act
        $body = (string) $app->controller->handle(Support::get('/account'), $user)->getBody();

        // Assert
        $this->assertStringNotContainsString('/account/mfa/disable', $body);
        $this->assertStringContainsString('cannot be turned off', $body);
    }
}
