<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\MessageBuilder;

use DateTimeImmutable;
use PhpSso\Mail\Email;
use PhpSso\Mail\MessageBuilder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(MessageBuilder::class, 'build')]
final class BuildTest extends TestCase
{
    private function builder(string $name = 'Acme SSO'): MessageBuilder
    {
        return new MessageBuilder('sso@example.com', $name, 'example.com');
    }

    private function build(Email $email, string $name = 'Acme SSO'): string
    {
        return $this->builder($name)->build($email, new DateTimeImmutable('2026-11-22 10:00:00 UTC'), 'BOUNDARY', 'id1@example.com');
    }

    public function testItHasTheHeadersEveryMailProgramNeeds(): void
    {
        // Arrange
        $email = new Email('alice@example.com', 'Hello', 'Text');

        // Act
        $message = $this->build($email);

        // Assert
        $this->assertStringContainsString("Date: Sun, 22 Nov 2026 10:00:00 +0000\r\n", $message);
        $this->assertStringContainsString("From: \"Acme SSO\" <sso@example.com>\r\n", $message);
        $this->assertStringContainsString("To: alice@example.com\r\n", $message);
        $this->assertStringContainsString("Subject: Hello\r\n", $message);
        $this->assertStringContainsString("Message-ID: <id1@example.com>\r\n", $message);
        $this->assertStringContainsString("MIME-Version: 1.0\r\n", $message);
        $this->assertStringContainsString("Auto-Submitted: auto-generated\r\n", $message);
    }

    public function testTextAloneIsASinglePartAndTextWithHtmlIsAlternatives(): void
    {
        // Arrange
        $textOnly = new Email('alice@example.com', 'Hello', 'Text');
        $both = new Email('alice@example.com', 'Hello', 'Text', '<p>Html</p>');

        // Act
        $single = $this->build($textOnly);
        $multi = $this->build($both);

        // Assert
        $this->assertStringNotContainsString('multipart', $single);
        $this->assertStringContainsString("Content-Type: multipart/alternative; boundary=\"BOUNDARY\"\r\n", $multi);
        $this->assertLessThan(strpos($multi, 'text/html'), strpos($multi, 'text/plain'), 'the HTML part comes last, so that it is the one preferred');
        $this->assertStringEndsWith("--BOUNDARY--\r\n", $multi);
    }

    public function testNonAsciiInTheSubjectAndNamesIsEncodedSoTheMessageIsPlainAscii(): void
    {
        // Arrange
        $email = new Email('alice@example.com', 'Wachtwoord opnieuw instellen, Zoë', "Hallo Zoë\n", '<p>Zoë</p>', 'Zoë Müller');

        // Act
        $message = $this->build($email, 'Société SSO');

        // Assert
        $this->assertMatchesRegularExpression('/^[\x00-\x7f]*$/', $message);
        preg_match('/^Subject: (.*?)\r\n(?!\s)/ms', $message, $subject);
        $decoded = implode('', array_map(static fn (string $word): string => base64_decode(substr($word, 10, -2)), preg_split('/\r\n /', $subject[1])));
        $this->assertSame('Wachtwoord opnieuw instellen, Zoë', $decoded);
        $this->assertStringContainsString('=?UTF-8?B?' . base64_encode('Zoë Müller') . '?= <alice@example.com>', $message);
        $this->assertStringContainsString('Zo=C3=AB', $message);
    }

    public function testALongNonAsciiSubjectIsSplitIntoEncodedWordsThatEachFit(): void
    {
        // Arrange
        $email = new Email('alice@example.com', str_repeat('Wachtwoord é ', 12), 'Text');

        // Act
        $message = $this->build($email);

        // Assert
        preg_match('/^Subject: (.*?)\r\n(?!\s)/ms', $message, $m);
        $words = preg_split('/\r\n /', $m[1]);
        $this->assertGreaterThan(1, count($words));
        foreach ($words as $word) {
            $this->assertLessThanOrEqual(75, strlen($word));
        }
        $this->assertSame(str_repeat('Wachtwoord é ', 12), trim(implode('', array_map(static fn (string $w): string => base64_decode(substr($w, 10, -2)), $words))) . ' ');
    }

    public function testEveryLineEndsInCrLfAndNoneIsTooLong(): void
    {
        // Arrange
        $text = "Open this:\nhttps://sso.example.com/password/reset/" . str_repeat('x', 120) . "\n\n.A line that starts with a dot\n";
        $email = new Email('alice@example.com', 'Hello', $text, '<p>' . str_repeat('word ', 80) . '</p>');

        // Act
        $message = $this->build($email);

        // Assert
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $message), 'no line ends in a bare LF');
        $this->assertSame(0, preg_match('/\r(?!\n)/', $message), 'no bare CR');
        foreach (explode("\r\n", $message) as $line) {
            $this->assertLessThanOrEqual(78, strlen($line));
        }
    }

    public function testASenderNameWithQuotesCannotBreakOutOfItsQuotes(): void
    {
        // Arrange
        $email = new Email('alice@example.com', 'Hello', 'Text');

        // Act
        $message = $this->build($email, 'Acme "Best" \\ SSO');

        // Assert
        $this->assertStringContainsString('From: "Acme \\"Best\\" \\\\ SSO" <sso@example.com>', $message);
    }
}
