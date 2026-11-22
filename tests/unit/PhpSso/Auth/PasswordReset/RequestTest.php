<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\PasswordReset;

use PhpSso\Auth\PasswordReset;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(PasswordReset::class, 'request')]
final class RequestTest extends TestCase
{
    public function testAPersonWhoMatchesGetsALinkOnceTheAnswerHasGone(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $app->service->request('alice', '203.0.113.5');
        $beforeTheAnswer = count($app->mailer->sent);
        $app->answerSent();

        // Assert
        $this->assertSame(0, $beforeTheAnswer, 'nothing is sent while the person waits');
        $this->assertCount(1, $app->mailer->sent);
        $email = $app->mailer->sent[0];
        $this->assertSame('alice@example.com', $email->to);
        $this->assertMatchesRegularExpression('#https://sso\.example\.com/password/reset/[A-Za-z0-9_-]{43}#', $email->text);
        $this->assertStringContainsString($app->tokenIn($email), (string) $email->html);
        $this->assertStringContainsString('60 minutes', $email->text);
        $this->assertStringContainsString('203.0.113.5', $email->text);
    }

    public function testTheEmailAddressWorksToo(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $app->service->request('  Alice@Example.com ', '203.0.113.5');
        $app->answerSent();

        // Assert
        $this->assertCount(1, $app->mailer->sent);
    }

    public function testNobodyIsToldWhetherTheAccountExistsAndNothingIsSentForAnUnknownOne(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $app->service->request('nobody-by-that-name', '203.0.113.5');
        $app->answerSent();

        // Assert
        $this->assertSame([], $app->mailer->sent);
        $this->assertSame(1, $app->resets->requestsFrom('203.0.113.5', 0), 'the request is still counted against the address');
    }

    public function testALockedAccountAndAnAccountWithoutAPasswordAreNeverSentALink(): void
    {
        // Arrange
        $app = new Support();
        $locked = $app->data->user('locked');
        $app->data->users->setLocked($locked, true);
        $app->data->users->create(['username' => 'external', 'password' => null, 'name' => 'External', 'email' => 'external@example.com', 'email_verified' => true, 'is_admin' => false]);

        // Act
        $app->service->request('locked', '203.0.113.5');
        $app->service->request('external', '203.0.113.5');
        $app->answerSent();

        // Assert
        $this->assertSame([], $app->mailer->sent);
    }

    public function testAnEmailAddressTwoPeopleShareIsNotUsedToPickOne(): void
    {
        // Arrange
        $app = new Support();
        foreach (['one', 'two'] as $name) {
            $app->data->users->create(['username' => $name, 'password' => 'secret-pass', 'name' => $name, 'email' => 'shared@example.com', 'email_verified' => true, 'is_admin' => false]);
        }

        // Act
        $app->service->request('shared@example.com', '203.0.113.5');
        $app->answerSent();

        // Assert
        $this->assertSame([], $app->mailer->sent);
    }

    public function testANewLinkReplacesTheOlderOne(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        $first = $app->link();

        // Act
        $second = $app->link();

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertNull($app->service->inspect($first));
        $this->assertSame('alice', $app->service->inspect($second)['username'] ?? null);
    }

    public function testAnAccountIsSentNoMoreThanThreeLinksAnHourAndTheFourthIsAnsweredLikeTheRest(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        for ($i = 0; $i < 5; $i++) {
            $app->service->request('alice', '203.0.113.' . (10 + $i), 1_000_000 + $i);
        }
        $app->answerSent();

        // Assert
        $this->assertCount(PasswordReset::REQUESTS_PER_ACCOUNT, $app->mailer->sent);
    }

    public function testTheLimitIsPerHourSoAnotherHourStartsAgain(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');
        for ($i = 0; $i < 4; $i++) {
            $app->service->request('alice', '203.0.113.' . (10 + $i), 1_000_000 + $i);
        }
        $app->answerSent();

        // Act
        $app->service->request('alice', '203.0.113.99', 1_000_000 + 3601);
        $app->answerSent();

        // Assert
        $this->assertCount(PasswordReset::REQUESTS_PER_ACCOUNT + 1, $app->mailer->sent);
    }

    public function testAnAddressIsLimitedToTenRequestsAnHourWhoeverItAsksFor(): void
    {
        // Arrange
        $app = new Support();
        foreach (range(1, 12) as $i) {
            $app->data->user('user' . $i);
        }

        // Act
        foreach (range(1, 12) as $i) {
            $app->service->request('user' . $i, '203.0.113.5', 1_000_000 + $i);
        }
        $app->answerSent();

        // Assert
        $this->assertCount(PasswordReset::REQUESTS_PER_ADDRESS, $app->mailer->sent);
    }

    public function testAMailServerThatFailsDoesNotShowInTheAnswer(): void
    {
        // Arrange
        $app = new Support(failingMail: true);
        $app->data->user('alice');
        $errors = sys_get_temp_dir() . '/reset-errors-' . bin2hex(random_bytes(4)) . '.log';
        $previous = ini_set('error_log', $errors);

        try {
            // Act
            $app->service->request('alice', '203.0.113.5');
            $app->answerSent();

            // Assert
            $this->assertStringContainsString('could not send a password reset email', (string) file_get_contents($errors));
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($errors);
        }
    }

    public function testNothingIsAskedOfAnEmptyForm(): void
    {
        // Arrange
        $app = new Support();
        $app->data->user('alice');

        // Act
        $app->service->request('   ', '203.0.113.5');
        $app->answerSent();

        // Assert
        $this->assertSame([], $app->mailer->sent);
    }
}
