<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\SpKeys;

use PhpSso\Integration\Saml\SpKeys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(SpKeys::class, 'status')]
final class StatusTest extends TestCase
{
    use UsesBackupFiles;

    public function testItDescribesTheKeysWithFingerprintAndExpiry(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $keys->stage();

        // Act
        $status = $keys->status();

        // Assert
        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $status['current']['fingerprint'] ?? '');
        $this->assertEqualsWithDelta(time() + 3650 * 86400, $status['current']['expires'] ?? 0, 300);
        $this->assertNotSame($status['current']['fingerprint'], $status['next']['fingerprint'] ?? null);
    }

    public function testWithoutKeysThereIsNothingToDescribe(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');

        // Act
        $status = $keys->status();

        // Assert
        $this->assertSame(['current' => null, 'next' => null], $status);
    }
}
