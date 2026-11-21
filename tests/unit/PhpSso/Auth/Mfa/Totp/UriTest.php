<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\Totp;

use PhpSso\Auth\Mfa\Totp;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Totp::class, 'uri')]
#[CoversMethod(Totp::class, 'generateSecret')]
final class UriTest extends TestCase
{
    public function testItBuildsAnOtpauthAddressWithEscapedLabels(): void
    {
        // Arrange
        $secret = 'GEZDGNBVGY3TQOJQ';

        // Act
        $uri = Totp::uri('Acme SSO', 'a.b@example.com', $secret);

        // Assert
        $this->assertSame('otpauth://totp/Acme%20SSO%3Aa.b%40example.com?secret=GEZDGNBVGY3TQOJQ&issuer=Acme%20SSO&algorithm=SHA1&digits=6&period=30', $uri);
    }

    public function testAGeneratedSecretIsTwentyBytesOfBase32(): void
    {
        // Arrange (nothing)

        // Act
        $secret = Totp::generateSecret();

        // Assert
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }
}
