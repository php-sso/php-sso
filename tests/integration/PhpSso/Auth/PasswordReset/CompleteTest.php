<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\PasswordReset;

use PhpSso\Auth\PasswordReset;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(PasswordReset::class, 'complete')]
final class CompleteTest extends TestCase
{
    private function alice(Support $app): string
    {
        return $app->data->user('alice');
    }

    public function testTheNewPasswordWorksAndTheOldOneDoesNot(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $token = $app->link();

        // Act
        $error = $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $hash = $app->data->users->find($sub)['password_hash'];
        $this->assertNull($error);
        $this->assertTrue(password_verify('a-new-password-1', $hash));
        $this->assertFalse(password_verify('secret-pass', $hash));
    }

    public function testEverySessionOfThePersonEndsAndTheirFailedSignInsAreForgotten(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $app->sessions->start($sub, '203.0.113.1', 'one');
        $app->sessions->start($sub, '203.0.113.2', 'two');
        $token = $app->link();

        // Act
        $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertSame([], array_values(array_filter($app->sessions->active(), static fn (array $s): bool => $s['sub'] === $sub)));
        $this->assertSame([[$sub, 'alice', 'alice@example.com']], $app->cleared);
    }

    public function testTheLinkWorksOnlyOnce(): void
    {
        // Arrange
        $app = new Support();
        $this->alice($app);
        $token = $app->link();
        $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Act
        $second = $app->service->complete($token, 'another-password-2', 'another-password-2', '203.0.113.5');

        // Assert
        $this->assertSame('This link is no longer valid.', $second);
        $this->assertNull($app->service->inspect($token));
    }

    public function testAPasswordThatIsTooShortOrDoesNotMatchLeavesTheLinkUsable(): void
    {
        // Arrange
        $app = new Support();
        $this->alice($app);
        $token = $app->link();

        // Act
        $short = $app->service->complete($token, 'short', 'short', '203.0.113.5');
        $mismatch = $app->service->complete($token, 'a-new-password-1', 'a-different-one-2', '203.0.113.5');
        $fine = $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertSame('Use at least 8 characters.', $short);
        $this->assertSame('The two passwords do not match.', $mismatch);
        $this->assertNull($fine);
    }

    public function testAnExpiredOrUnknownLinkChangesNothing(): void
    {
        // Arrange
        $app = new Support(minutes: 10);
        $sub = $this->alice($app);
        $app->service->request('alice', '203.0.113.5', 1_000_000);
        $app->answerSent();
        $token = $app->tokenIn($app->mailer->sent[0]);
        $before = $app->data->users->find($sub)['password_hash'];

        // Act
        $expired = $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5', 1_000_000 + 601);
        $unknown = $app->service->complete(str_repeat('a', 43), 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertSame('This link is no longer valid.', $expired);
        $this->assertSame('This link is no longer valid.', $unknown);
        $this->assertSame($before, $app->data->users->find($sub)['password_hash']);
    }

    public function testAnAccountThatWasLockedInTheMeantimeCannotUseTheLink(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $token = $app->link();
        $app->data->users->setLocked($sub, true);

        // Act
        $error = $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertSame('This link is no longer valid.', $error);
    }

    public function testALinkSomeoneElseUsedBetweenTheCheckAndTheChangeIsNotUsedTwice(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $token = $app->link();
        $before = $app->data->users->find($sub)['password_hash'];
        $app->resets->consume($app->resets->find(PasswordReset::hash($token))['id']);

        // Act
        $error = $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertSame('This link is no longer valid.', $error);
        $this->assertSame($before, $app->data->users->find($sub)['password_hash']);
    }

    public function testItIsRecordedInTheAuditLogAndTheChangeIsAnnouncedToThePersonAfterTheAnswer(): void
    {
        // Arrange
        $app = new Support();
        $sub = $this->alice($app);
        $token = $app->link();
        $sentBefore = count($app->mailer->sent);

        // Act
        $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '198.51.100.9');
        $immediately = count($app->mailer->sent);
        $app->answerSent();

        // Assert
        $this->assertSame('account.password_reset', $app->audit->entries[0]['action']);
        $this->assertSame($sub, $app->audit->entries[0]['actor_sub']);
        $this->assertSame('198.51.100.9', $app->audit->entries[0]['ip']);
        $this->assertSame($sentBefore, $immediately);
        $notice = $app->mailer->sent[array_key_last($app->mailer->sent)];
        $this->assertSame('alice@example.com', $notice->to);
        $this->assertStringContainsString('was changed', $notice->subject);
        $this->assertStringContainsString('198.51.100.9', $notice->text);
        $this->assertStringNotContainsString('a-new-password-1', $notice->text . $notice->html);
    }

    public function testAnAdministratorsRequestToChooseANewPasswordIsSatisfiedToo(): void
    {
        // Arrange
        $app = new Support();
        $sub = $app->data->users->create(['username' => 'bob', 'password' => 'temporary-1', 'name' => 'Bob', 'email' => 'bob@example.com', 'email_verified' => true, 'is_admin' => false, 'must_change_password' => true]);
        $token = $app->link('bob');

        // Act
        $app->service->complete($token, 'a-new-password-1', 'a-new-password-1', '203.0.113.5');

        // Assert
        $this->assertFalse((bool) $app->data->users->find($sub)['must_change_password']);
    }
}
