<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\SmtpMailer;

use PhpSso\Mail\Email;
use PhpSso\Mail\MailFailed;
use PhpSso\Mail\MessageBuilder;
use PhpSso\Mail\SmtpMailer;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeSmtp;

#[CoversMethod(SmtpMailer::class, 'send')]
final class SendTest extends TestCase
{
    private const GREETING = ['220 mail.example.com ESMTP ready'];

    private function mailer(FakeSmtp $server, string $encryption = 'none', string $user = '', string $password = ''): SmtpMailer
    {
        return new SmtpMailer(static fn (): FakeSmtp => $server, new MessageBuilder('sso@example.com', 'SSO', 'example.com'), 'sso@example.com', $encryption, $user, $password, 'sso.example.com');
    }

    private static function email(string $text = 'Hello'): Email
    {
        return new Email('alice@example.com', 'Reset your password', $text);
    }

    public function testItSaysHelloGivesTheEnvelopeAndTheMessageAndSaysGoodbye(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250 mail.example.com'], ['250 OK'], ['250 OK'], ['354 Go ahead'], ['250 Queued'], ['221 Bye']]);

        // Act
        $this->mailer($server)->send(self::email());

        // Assert
        $this->assertSame(['EHLO sso.example.com', 'MAIL FROM:<sso@example.com>', 'RCPT TO:<alice@example.com>', 'DATA', 'QUIT'], $server->commands());
        $this->assertStringContainsString('Subject: Reset your password', $server->message());
        $this->assertStringEndsWith("\r\n.\r\n", $server->message());
        $this->assertTrue($server->closed);
    }

    public function testWithStarttlsTheConnectionIsSecuredBeforeAnythingIsSentAndHelloIsRepeated(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250-STARTTLS', '250 8BITMIME'], ['220 Ready to start TLS'], ['250 mail.example.com'], ['250 OK'], ['250 OK'], ['354 Go'], ['250 Queued'], ['221 Bye']]);

        // Act
        $this->mailer($server, 'starttls')->send(self::email());

        // Assert
        $commands = $server->commands();
        $this->assertTrue($server->tls);
        $this->assertSame(['EHLO sso.example.com', 'STARTTLS', 'EHLO sso.example.com'], array_slice($commands, 0, 3));
        $this->assertSame('MAIL FROM:<sso@example.com>', $commands[3]);
    }

    public function testAServerThatDoesNotOfferStarttlsIsNotUsedUnencrypted(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250 8BITMIME']]);
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('does not offer STARTTLS');

        // Act
        try {
            $this->mailer($server, 'starttls', 'user', 'secret')->send(self::email());
        } finally {
            // Assert (also: nothing but the greeting went over the line, and no credentials)
            $this->assertSame(['EHLO sso.example.com'], $server->commands());
            $this->assertFalse($server->tls);
            $this->assertTrue($server->closed);
        }
    }

    public function testWhenTheCertificateIsNotAcceptedNoCredentialsAreSent(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250-STARTTLS', '250 AUTH PLAIN'], ['220 Ready']], tlsWorks: false);
        $this->expectException(MailFailed::class);

        // Act
        try {
            $this->mailer($server, 'starttls', 'user', 'secret')->send(self::email());
        } finally {
            // Assert
            foreach ($server->written as $written) {
                $this->assertStringNotContainsString('AUTH', $written);
                $this->assertStringNotContainsString(base64_encode("\0user\0secret"), $written);
            }
        }
    }

    public function testItSignsInWithPlainAndTheCredentialsAreInTheFirstCommand(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250 AUTH PLAIN LOGIN'], ['235 Authenticated'], ['250 OK'], ['250 OK'], ['354 Go'], ['250 Queued'], ['221 Bye']]);

        // Act
        $this->mailer($server, 'none', 'user', 'secret')->send(self::email());

        // Assert
        $this->assertSame('AUTH PLAIN ' . base64_encode("\0user\0secret"), $server->commands()[1]);
    }

    public function testItSignsInWithLoginWhenThatIsAllThereIs(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250 AUTH=LOGIN'], ['334 VXNlcm5hbWU6'], ['334 UGFzc3dvcmQ6'], ['235 Authenticated'], ['250 OK'], ['250 OK'], ['354 Go'], ['250 Queued'], ['221 Bye']]);

        // Act
        $this->mailer($server, 'none', 'user', 'secret')->send(self::email());

        // Assert
        $this->assertSame(['AUTH LOGIN', base64_encode('user'), base64_encode('secret')], array_slice($server->commands(), 1, 3));
    }

    public function testAServerWithNoSupportedSignInIsRefused(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250 AUTH CRAM-MD5 XOAUTH2']]);
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('PLAIN or LOGIN');

        // Act
        $this->mailer($server, 'none', 'user', 'secret')->send(self::email());

        // Assert (the exception above)
    }

    public function testRejectedCredentialsAreReportedWithoutTheyBeingInTheMessage(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250-mail.example.com', '250 AUTH PLAIN'], ['535 5.7.8 Authentication credentials invalid']]);

        // Act
        try {
            $this->mailer($server, 'none', 'user', 'secret-pass-123')->send(self::email());
            $this->fail('it should have failed');
        } catch (MailFailed $e) {
            // Assert
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringContainsString('sign-in', $e->getMessage());
            $this->assertStringNotContainsString('secret-pass-123', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode("\0user\0secret-pass-123"), $e->getMessage());
        }
    }

    public function testARecipientTheServerRefusesIsAFailureAndNoMessageIsSent(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250 mail.example.com'], ['250 OK'], ['550 5.1.1 No such user']]);
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('550');

        // Act
        try {
            $this->mailer($server)->send(self::email());
        } finally {
            // Assert
            $this->assertNotContains('DATA', $server->commands());
            $this->assertTrue($server->closed);
        }
    }

    public function testALineThatStartsWithADotIsDoubledSoItCannotEndTheMessage(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250 mail.example.com'], ['250 OK'], ['250 OK'], ['354 Go'], ['250 Queued'], ['221 Bye']]);

        // Act
        $this->mailer($server)->send(self::email("First\n.\n.second\nlast"));

        // Assert
        $this->assertStringContainsString("\r\n..\r\n..second\r\n", $server->message());
        $this->assertSame(1, substr_count($server->message(), "\r\n.\r\n"), 'only the end of the message is a lone dot');
    }

    public function testAGreetingThatIsNotWelcomingIsAFailure(): void
    {
        // Arrange
        $server = new FakeSmtp([['554 No SMTP service here']]);
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('554');

        // Act
        try {
            $this->mailer($server)->send(self::email());
        } finally {
            // Assert
            $this->assertTrue($server->closed);
        }
    }

    public function testSomethingThatIsNotAnSmtpReplyIsAFailure(): void
    {
        // Arrange
        $server = new FakeSmtp([['HTTP/1.1 400 Bad Request']]);
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('not an SMTP reply');

        // Act
        $this->mailer($server)->send(self::email());

        // Assert (the exception above)
    }

    public function testAServerThatIsRudeAboutQuittingIsNotAFailure(): void
    {
        // Arrange
        $server = new FakeSmtp([self::GREETING, ['250 mail.example.com'], ['250 OK'], ['250 OK'], ['354 Go'], ['250 Queued']]);

        // Act
        $this->mailer($server)->send(self::email());

        // Assert
        $this->assertTrue($server->closed);
        $this->assertStringContainsString('Subject:', $server->message());
    }
}
