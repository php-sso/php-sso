<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\SpKeys;

use PhpSso\Integration\Saml\SpKeys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(SpKeys::class, 'ensure')]
#[CoversMethod(SpKeys::class, 'current')]
final class EnsureTest extends TestCase
{
    use UsesBackupFiles;

    public function testThereIsNoKeyUntilOneIsMade(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');

        // Act
        $current = $keys->current();

        // Assert
        $this->assertNull($current);
    }

    public function testItMakesAKeyOnlyTheServerCanReadAndAPublicCertificate(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');

        // Act
        $keys->ensure();

        // Assert
        $this->assertSame(0600, fileperms($this->work . '/keys/saml-sp.key') & 0777);
        $this->assertSame(0644, fileperms($this->work . '/keys/saml-sp.crt') & 0777);
        $current = $keys->current();
        $this->assertNotNull($current);
        $this->assertNull($current->nextCertificate);
        $this->assertSame('sso.example.com', openssl_x509_parse($current->certificate)['subject']['CN']);
    }

    public function testAskingAgainKeepsTheKeyThatIsThere(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $before = $keys->current()?->certificate;

        // Act
        $keys->ensure();

        // Assert
        $this->assertSame($before, $keys->current()?->certificate);
    }

    public function testAKeyWithoutItsCertificateCountsAsNone(): void
    {
        // Arrange
        mkdir($this->work . '/keys', 0700);
        file_put_contents($this->work . '/keys/saml-sp.key', 'half written');
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');

        // Act
        $current = $keys->current();

        // Assert
        $this->assertNull($current);
    }
}
