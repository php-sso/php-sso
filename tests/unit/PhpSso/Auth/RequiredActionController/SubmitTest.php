<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\RequiredActionController;

use PhpSso\Auth\RequiredActionController;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support;

#[CoversMethod(RequiredActionController::class, 'submit')]
final class SubmitTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAGoodPasswordSignsThePersonIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $_SESSION['return_to'] = '/account';
        $app->flow->beginActions($sub, 'alice');

        // Act
        $response = $app->requiredController->submit(Support::post(['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']));

        // Assert
        $this->assertSame('/account', $response->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertSame(0, (int) $app->data->users->find($sub)['must_change_password']);
    }

    public function testABadPasswordShowsThePageAgainWithTheReason(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $app->flow->beginActions($sub, 'alice');

        // Act
        $response = $app->requiredController->submit(Support::post(['new_password' => 'short', 'confirm_password' => 'short']));

        // Assert
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Use at least 8 characters.', (string) $response->getBody());
        $this->assertArrayNotHasKey('user', $_SESSION);
    }

    public function testARequestWithoutTheRightTokenIsRefused(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $app->flow->beginActions($sub, 'alice');

        // Act
        $response = $app->requiredController->submit(Support::post(['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one', '_csrf' => 'wrong']));

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, (int) $app->data->users->find($sub)['must_change_password']);
    }

    public function testSomeoneWhoNeverProvedWhoTheyAreCannotSetAPassword(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);

        // Act
        $response = $app->requiredController->submit(Support::post(['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']));

        // Assert
        $this->assertSame('/login', $response->getHeaderLine('Location'));
        $this->assertSame(1, (int) $app->data->users->find($sub)['must_change_password']);
    }

    public function testNoticesAreAskedOneByOneBeforeThePasswordAndThenThePersonIsSignedIn(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->data->users->setMustChangePassword($sub, true);
        $first = $app->consents->create('Terms', 'a', null, true);
        $second = $app->consents->create('Policy', 'b', null, true);
        $_SESSION['return_to'] = '/account';
        $app->flow->beginActions($sub, 'alice');

        // Act
        $one = $app->requiredController->submit(Support::post(['consent_id' => (string) $first, 'version' => '1', 'accept' => '1']));
        $two = $app->requiredController->submit(Support::post(['consent_id' => (string) $second, 'version' => '1', 'accept' => '1']));
        $page = (string) $app->requiredController->form()->getBody();
        $done = $app->requiredController->submit(Support::post(['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one']));

        // Assert
        $this->assertSame('/login/required', $one->getHeaderLine('Location'));
        $this->assertSame('/login/required', $two->getHeaderLine('Location'));
        $this->assertStringContainsString('name="new_password"', $page);
        $this->assertSame('/account', $done->getHeaderLine('Location'));
        $this->assertSame($sub, $_SESSION['user']);
    }

    public function testTheNoticePageShowsTheMessageAndTheLink(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->user('alice');
        $app->consents->create('Terms of use', "Line one\nLine <b>two</b>", 'https://example.com/terms', true);
        $app->flow->beginActions($sub, 'alice');

        // Act
        $html = (string) $app->requiredController->form()->getBody();

        // Assert
        $this->assertStringContainsString('<h1>Terms of use</h1>', $html);
        $this->assertStringContainsString('Line one<br />', $html);
        $this->assertStringContainsString('Line &lt;b&gt;two&lt;/b&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/terms"', $html);
        $this->assertStringContainsString('name="accept"', $html);
    }
}
