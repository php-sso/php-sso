<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Startup\Checks;

use PhpSso\Startup\Checks;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Checks::class, 'mailProblems')]
final class MailProblemsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function mail(array $change = []): array
    {
        return $change + ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'from' => 'sso@example.com', 'ca_file' => ''];
    }

    public function testWithNoMailServerThereIsNothingToCheck(): void
    {
        // Arrange
        $mail = ['host' => '', 'from' => 'not even an address', 'encryption' => 'carrier pigeon', 'port' => 0, 'ca_file' => '/nonexistent'];

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertSame([], $problems);
    }

    public function testASensibleSetupIsFine(): void
    {
        // Arrange
        $mail = self::mail();

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertSame([], $problems);
    }

    public function testAServerWithNoSenderAddressIsRefused(): void
    {
        // Arrange
        $mail = self::mail(['from' => '']);

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('SSO_MAIL_FROM', $problems[0]);
    }

    public function testAnUnknownEncryptionAndABadPortAreRefused(): void
    {
        // Arrange
        $mail = self::mail(['encryption' => 'ssl3', 'port' => 99999]);

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertCount(2, $problems);
    }

    /** @return array<string, array{string, bool}> */
    public static function plainHosts(): array
    {
        return [
            'a public name' => ['smtp.example.com', true],
            'localhost' => ['localhost', false],
            'a loopback address' => ['127.0.0.1', false],
            'a container on the same network' => ['mailpit', false],
            'a private address' => ['10.0.0.5', false],
            'another private address' => ['192.168.1.20', false],
            'a public address' => ['8.8.8.8', true],
        ];
    }

    #[DataProvider('plainHosts')]
    public function testUnencryptedMailIsOnlyForAServerOnThisMachineOrANetworkTheNameOfWhichSaysSo(string $host, bool $refused): void
    {
        // Arrange
        $mail = self::mail(['host' => $host, 'encryption' => 'none', 'port' => 25]);

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertSame($refused, $problems !== []);
    }

    public function testACertificateFileThatCannotBeReadIsRefused(): void
    {
        // Arrange
        $mail = self::mail(['ca_file' => '/nonexistent/ca.pem']);

        // Act
        $problems = Checks::mailProblems($mail);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('SSO_SMTP_CA_FILE', $problems[0]);
    }
}
