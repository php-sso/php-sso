<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Account\AccountController;

use Tests\integration\PhpSso\Account\AccountController\Support;

use PhpSso\Account\AccountController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Auth\LoginController\Support as LoginSupport;

#[CoversMethod(AccountController::class, 'handle')]
final class AuditTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAChangedPasswordIsRecordedWithWhoAndFromWhere(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::post('/account/password', ['current_password' => 'secret-pass', 'new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']), $user);

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $app->audit->entries);
        $this->assertSame('account.password_change', $app->audit->entries[0]['action']);
        $this->assertSame('alice', $app->audit->entries[0]['actor']);
        $this->assertSame($user['sub'], $app->audit->entries[0]['actor_sub']);
        $this->assertSame('203.0.113.9', $app->audit->entries[0]['ip']);
    }

    public function testNeitherThePasswordNorAnythingElsePostedReachesTheLog(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $app->controller->handle(Support::post('/account/password', ['current_password' => 'secret-pass', 'new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']), $user);

        // Assert
        $this->assertStringNotContainsString('a-brand-new-one', json_encode($app->audit->entries));
        $this->assertStringNotContainsString('secret-pass', json_encode($app->audit->entries));
    }

    public function testAPasswordChangeThatWasRefusedIsNotRecorded(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::post('/account/password', ['current_password' => 'not-it', 'new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']), $user);

        // Assert
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $app->audit->entries);
    }

    public function testSettingUpTwoStepVerificationIsRecordedOnlyWhenTheCodeWasRight(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->controller->handle(Support::get('/account/mfa/setup'), $user);
        $secret = $_SESSION['mfa_setup'];
        $app->controller->handle(Support::post('/account/mfa/setup', ['code' => '000000']), $user);
        $afterWrongCode = count($app->audit->entries);

        // Act
        $app->controller->handle(Support::post('/account/mfa/setup', ['code' => LoginSupport::codeFor($secret)]), $user);

        // Assert
        $this->assertSame(0, $afterWrongCode);
        $this->assertSame(['account.mfa_enable'], array_column($app->audit->entries, 'action'));
    }

    public function testARedirectThatChangedNothingIsNotRecorded(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $response = $app->controller->handle(Support::post('/account/mfa/setup', ['code' => '123456']), $user);

        // Assert
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $app->audit->entries);
    }

    public function testJustLookingAtThePageIsNotRecorded(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));

        // Act
        $app->controller->handle(Support::get('/account'), $user);

        // Assert
        $this->assertSame([], $app->audit->entries);
    }

    public function testAnInvalidProfileIsNotRecordedAndAValidOneIs(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->login->data->users->find($app->login->data->user('alice'));
        $app->controller->handle(Support::post('/account/profile', ['name' => 'Alice', 'email' => 'not-an-email']), $user);
        $afterInvalid = count($app->audit->entries);

        // Act
        $app->controller->handle(Support::post('/account/profile', ['name' => 'Alice B', 'email' => 'alice@example.com']), $user);

        // Assert
        $this->assertSame(0, $afterInvalid);
        $this->assertSame(['account.profile_update'], array_column($app->audit->entries, 'action'));
    }
}
