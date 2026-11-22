<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\SmtpMailer;

use InvalidArgumentException;
use PhpSso\Mail\MessageBuilder;
use PhpSso\Mail\SmtpMailer;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeSmtp;

#[CoversMethod(SmtpMailer::class, '__construct')]
final class __constructTest extends TestCase
{
    private function make(string $from = 'sso@example.com', string $encryption = 'starttls', string $helo = 'sso.example.com'): SmtpMailer
    {
        return new SmtpMailer(static fn (): FakeSmtp => new FakeSmtp([]), new MessageBuilder('sso@example.com', 'SSO', 'example.com'), $from, $encryption, '', '', $helo);
    }

    public function testAnUnknownEncryptionIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        $this->make(encryption: 'ssl3');

        // Assert (the exception above)
    }

    public function testASenderOrAHelloNameThatCouldInjectACommandIsRefused(): void
    {
        // Arrange
        $bad = [fn () => $this->make(from: "sso@example.com>\r\nRCPT TO:<x@example.com"), fn () => $this->make(helo: "host\r\nMAIL FROM:<x@y.z>")];

        // Act
        $refused = 0;
        foreach ($bad as $make) {
            try {
                $make();
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }

        // Assert
        $this->assertSame(2, $refused);
    }
}
