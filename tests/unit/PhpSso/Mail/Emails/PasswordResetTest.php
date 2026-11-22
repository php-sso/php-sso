<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\Emails;

use PhpSso\Mail\Emails;
use PhpSso\Pages;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(Emails::class, 'passwordReset')]
#[CoversMethod(Emails::class, 'passwordChanged')]
final class PasswordResetTest extends TestCase
{
    use UsesBackupFiles;

    private const USER = ['name' => "Zoë O'Brien <script>", 'username' => 'zoe', 'email' => 'zoe@example.com'];

    private function pages(?string $theme = null, bool $debug = false): Pages
    {
        $root = dirname(__DIR__, 5);

        return new Pages($root . '/templates/pages', $theme ?? '/nonexistent', $this->work . '/cache-' . bin2hex(random_bytes(3)), $root . '/public', ['app_name' => 'Acme SSO'], $debug);
    }

    public function testTheResetEmailHasASubjectTheLinkAndBothParts(): void
    {
        // Arrange
        $emails = new Emails($this->pages());

        // Act
        $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 45, '203.0.113.5');

        // Assert
        $this->assertSame('Choose a new password for Acme SSO', $email->subject);
        $this->assertSame('zoe@example.com', $email->to);
        $this->assertStringContainsString('https://sso.example.com/password/reset/TOKEN', $email->text);
        $this->assertStringContainsString('href="https://sso.example.com/password/reset/TOKEN"', (string) $email->html);
        $this->assertStringContainsString('45 minutes', $email->text);
        $this->assertStringContainsString('45 minutes', (string) $email->html);
    }

    public function testThePlainTextIsNotEscapedAndTheHtmlIs(): void
    {
        // Arrange
        $emails = new Emails($this->pages());

        // Act
        $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '203.0.113.5');

        // Assert
        $this->assertStringContainsString("Hello Zoë O'Brien <script>,", $email->text);
        $this->assertStringContainsString('Hello Zoë O&#039;Brien &lt;script&gt;,', (string) $email->html);
        $this->assertStringNotContainsString('<script>', (string) $email->html);
    }

    public function testTheSubjectIsOneLineAndIsNotInTheBody(): void
    {
        // Arrange
        $emails = new Emails($this->pages());

        // Act
        $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '203.0.113.5');

        // Assert
        $this->assertStringNotContainsString("\n", $email->subject);
        $this->assertStringStartsWith('Hello', ltrim($email->text));
    }

    public function testAnAdministratorsLinkSaysSoAndDoesNotTalkAboutARequest(): void
    {
        // Arrange
        $emails = new Emails($this->pages());

        // Act
        $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '198.51.100.1', byAdmin: true);

        // Assert
        $this->assertStringContainsString('An administrator of Acme SSO sent you this link', $email->text);
        $this->assertStringNotContainsString('198.51.100.1', $email->text);
    }

    public function testTheNoticeSaysWhenAndFromWhereAndNeverHasAPasswordOrLink(): void
    {
        // Arrange
        $emails = new Emails($this->pages());

        // Act
        $email = $emails->passwordChanged(self::USER, '203.0.113.5', 1_800_000_000);

        // Assert
        $this->assertSame('Your Acme SSO password was changed', $email->subject);
        $this->assertStringContainsString('2027-01-15 08:00 UTC', $email->text);
        $this->assertStringContainsString('203.0.113.5', $email->text);
        $this->assertStringNotContainsString('http', $email->text);
    }

    public function testAThemeReplacesAnEmailByTheSameName(): void
    {
        // Arrange
        mkdir($this->work . '/theme/templates', 0777, true);
        file_put_contents($this->work . '/theme/templates/email_password_reset.txt.twig', "{% extends '@sso/email_password_reset.txt.twig' %}{% block subject %}Your Acme sign-in{% endblock %}");
        $emails = new Emails($this->pages($this->work . '/theme'));

        // Act
        $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '203.0.113.5');

        // Assert
        $this->assertSame('Your Acme sign-in', $email->subject);
        $this->assertStringContainsString('https://sso.example.com/password/reset/TOKEN', $email->text, 'the rest of the email is the built-in one');
    }

    public function testAThemeThatFailsFallsBackToTheBuiltInEmail(): void
    {
        // Arrange
        mkdir($this->work . '/theme/templates', 0777, true);
        file_put_contents($this->work . '/theme/templates/email_password_reset.html.twig', '{{ not_a_variable }}');
        $emails = new Emails($this->pages($this->work . '/theme'));
        $errors = $this->work . '/errors.log';
        $previous = ini_set('error_log', $errors);

        try {
            // Act
            $email = $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '203.0.113.5');

            // Assert
            $this->assertStringContainsString('Choose a new password', (string) $email->html);
            $this->assertStringContainsString('SSO theme error in the email password_reset', (string) file_get_contents($errors));
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testWithDebugOnAThemeErrorIsNotHidden(): void
    {
        // Arrange
        mkdir($this->work . '/theme/templates', 0777, true);
        file_put_contents($this->work . '/theme/templates/email_password_reset.html.twig', '{{ not_a_variable }}');
        $emails = new Emails($this->pages($this->work . '/theme', debug: true));
        $this->expectException(\Twig\Error\Error::class);

        // Act
        $emails->passwordReset(self::USER, 'https://sso.example.com/password/reset/TOKEN', 60, '203.0.113.5');

        // Assert (the exception above)
    }
}
