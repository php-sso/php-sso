<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\Emails;

use PhpSso\Mail\Emails;
use PhpSso\Pages;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(Emails::class, 'passwordReset')]
final class PasswordResetButtonTest extends TestCase
{
    use UsesBackupFiles;

    private const USER = ['name' => 'Zoe', 'username' => 'zoe', 'email' => 'zoe@example.com'];

    public function testTheBuiltInEmailEndsWithTheDocumentAndPrintsNoStrayStyle(): void
    {
        // Arrange
        $emails = new Emails($this->pages('/nonexistent'));

        // Act
        $html = (string) $emails->passwordReset(self::USER, 'https://sso.example.com/r/T', 45, '203.0.113.5')->html;

        // Assert
        $this->assertMatchesRegularExpression('~</html>\s*$~', $html);
        $this->assertStringContainsString('background:#0f6b7a', $html);
    }

    public function testAThemeRestylesTheButtonByExtendingTheBaseOnly(): void
    {
        // Arrange
        $theme = $this->work . '/theme';
        mkdir($theme . '/templates', 0777, true);
        file_put_contents($theme . '/templates/_email.html.twig', "{% extends '@sso/_email.html.twig' %}{% block email_button_style %}background:#123456;{% endblock %}");
        $emails = new Emails($this->pages($theme));

        // Act
        $html = (string) $emails->passwordReset(self::USER, 'https://sso.example.com/r/T', 45, '203.0.113.5')->html;

        // Assert
        $this->assertStringContainsString('style="background:#123456;"', $html);
        $this->assertStringNotContainsString('#0f6b7a', $html);
    }

    private function pages(string $theme): Pages
    {
        $root = dirname(__DIR__, 5);

        return new Pages($root . '/templates/pages', $theme, $this->work . '/cache-' . bin2hex(random_bytes(3)), $root . '/public', ['app_name' => 'Acme SSO'], true);
    }
}
