<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\PasswordReset;

use DomainException;
use PhpSso\Mail\MailFailed;
use PhpSso\Auth\PasswordReset;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(PasswordReset::class, 'sendTo')]
final class SendToTest extends TestCase
{
    public function testTheLinkIsSentAtOnceAndSaysAnAdministratorSentIt(): void
    {
        // Arrange
        $app = new Support();
        $user = $app->data->users->find($app->data->user('alice'));

        // Act
        $app->service->sendTo($user, '198.51.100.1');

        // Assert
        $this->assertCount(1, $app->mailer->sent);
        $this->assertStringContainsString('An administrator of Acme SSO sent you this link', $app->mailer->sent[0]->text);
        $this->assertStringNotContainsString('If you did not ask for this', $app->mailer->sent[0]->text);
        $this->assertNotNull($app->service->inspect($app->tokenIn($app->mailer->sent[0])));
    }

    public function testALockedAccountOrOneWithoutAPasswordIsRefusedWithTheReason(): void
    {
        // Arrange
        $app = new Support();
        $locked = $app->data->user('locked');
        $app->data->users->setLocked($locked, true);
        $externalSub = $app->data->users->create(['username' => 'external', 'password' => null, 'name' => 'External', 'email' => 'external@example.com', 'email_verified' => true, 'is_admin' => false]);

        // Act
        $reasons = [];
        foreach ([$locked, $externalSub] as $sub) {
            try {
                $app->service->sendTo($app->data->users->find($sub), '198.51.100.1');
            } catch (DomainException $e) {
                $reasons[] = $e->getMessage();
            }
        }

        // Assert
        $this->assertSame(['The account is locked: unlock it first.', 'The account has no password: the person signs in another way.'], $reasons);
        $this->assertSame([], $app->mailer->sent);
    }

    public function testAMailServerThatFailsIsReportedToTheAdministrator(): void
    {
        // Arrange
        $app = new Support(failingMail: true);
        $user = $app->data->users->find($app->data->user('alice'));
        $this->expectException(MailFailed::class);

        // Act
        $app->service->sendTo($user, '198.51.100.1');

        // Assert (the exception above)
    }
}
