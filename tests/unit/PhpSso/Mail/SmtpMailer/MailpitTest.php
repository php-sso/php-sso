<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\SmtpMailer;

use PhpSso\Mail\Email;
use PhpSso\Mail\MailFactory;
use PhpSso\Mail\MailFailed;
use PhpSso\Mail\SmtpMailer;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Sends through a real SMTP server and reads what arrived. It needs a Mailpit (https://mailpit.axllent.org): SSO_TEST_SMTP is its SMTP address
 * (`host:port`) and SSO_TEST_MAILPIT its web address (`http://host:8025`). The development compose file has one; without it these tests are skipped.
 */
#[CoversMethod(SmtpMailer::class, 'send')]
final class MailpitTest extends TestCase
{
    private string $api;
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $smtp = getenv('SSO_TEST_SMTP');
        $api = getenv('SSO_TEST_MAILPIT');
        if (!is_string($smtp) || $smtp === '' || !is_string($api) || $api === '') {
            $this->markTestSkipped('No Mailpit: set SSO_TEST_SMTP and SSO_TEST_MAILPIT to run the tests that send through a real SMTP server.');
        }
        [$this->host, $port] = explode(':', $smtp) + [1 => '1025'];
        $this->port = (int) $port;
        $this->api = rtrim($api, '/');
        $this->request('DELETE', '/api/v1/messages');
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path): array
    {
        $json = @file_get_contents($this->api . $path, false, stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]));

        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    private function mailer(array $change = []): SmtpMailer
    {
        return MailFactory::create($change + ['host' => $this->host, 'port' => $this->port, 'encryption' => 'none', 'username' => '', 'password' => '', 'from' => 'sso@example.com', 'from_name' => 'Acme SSO', 'ca_file' => '', 'timeout' => 5], 'https://sso.example.com', 'SSO');
    }

    /** @return array<string, mixed> the one message that arrived, in full */
    private function arrived(): array
    {
        $list = $this->request('GET', '/api/v1/messages');
        $this->assertSame(1, $list['messages_count'] ?? $list['total'] ?? 0, 'exactly one message arrived');

        return $this->request('GET', '/api/v1/message/' . $list['messages'][0]['ID']);
    }

    public function testAMessageArrivesWithItsNamesSubjectAndBothPartsIntact(): void
    {
        // Arrange
        $email = new Email('zoe@example.com', 'Wachtwoord opnieuw instellen, Zoë', "Hallo Zoë,\n\nOpen: https://sso.example.com/password/reset/abc\n.A line that starts with a dot\nEinde\n", '<p>Hallo <b>Zoë</b></p>', 'Zoë Müller');

        // Act
        $this->mailer()->send($email);

        // Assert
        $message = $this->arrived();
        $this->assertSame('Wachtwoord opnieuw instellen, Zoë', $message['Subject']);
        $this->assertSame('sso@example.com', $message['From']['Address']);
        $this->assertSame('Acme SSO', $message['From']['Name']);
        $this->assertSame('Zoë Müller', $message['To'][0]['Name']);
        $this->assertSame('zoe@example.com', $message['To'][0]['Address']);
        $this->assertStringContainsString("\r\n.A line that starts with a dot\r\nEinde", $message['Text']);
        $this->assertStringContainsString('Hallo <b>Zoë</b>', $message['HTML']);
    }

    public function testSigningInIsAcceptedByAServerThatAsksForIt(): void
    {
        // Arrange
        $email = new Email('zoe@example.com', 'With a login', 'Hello');

        // Act
        $this->mailer(['username' => 'sso', 'password' => 'a-password'])->send($email);

        // Assert
        $this->assertSame('With a login', $this->arrived()['Subject']);
    }

    public function testAServerWithoutStarttlsIsNotUsedUnencryptedWhenStarttlsIsAsked(): void
    {
        // Arrange
        $email = new Email('zoe@example.com', 'Must not arrive', 'Hello');
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('does not offer STARTTLS');

        // Act
        try {
            $this->mailer(['encryption' => 'starttls', 'username' => 'sso', 'password' => 'a-password'])->send($email);
        } finally {
            // Assert (also: nothing was delivered)
            $this->assertSame(0, $this->request('GET', '/api/v1/messages')['messages_count'] ?? 0);
        }
    }

    public function testAServerThatCannotBeReachedIsAClearError(): void
    {
        // Arrange
        $email = new Email('zoe@example.com', 'Nowhere', 'Hello');
        $this->expectException(MailFailed::class);
        $this->expectExceptionMessage('Cannot connect to the mail server');

        // Act
        $this->mailer(['port' => 1])->send($email);

        // Assert (the exception above)
    }
}
