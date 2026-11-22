<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Startup\Checks;

use PhpSso\Startup\Checks;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Checks::class, 'problems')]
final class ProblemsTest extends TestCase
{
    public function testAnHttpsAddressIsFine(): void
    {
        // Arrange
        $issuer = 'https://sso.example.com';

        // Act
        $problems = Checks::problems($issuer, false, false);

        // Assert
        $this->assertSame([], $problems);
    }

    #[DataProvider('localAddresses')]
    public function testALocalAddressMayBeHttp(string $issuer): void
    {
        // Arrange (the address)

        // Act
        $problems = Checks::problems($issuer, true, false);

        // Assert
        $this->assertSame([], $problems);
    }

    /** @return array<string, array{string}> */
    public static function localAddresses(): array
    {
        return ['localhost' => ['http://localhost:9000'], 'loopback' => ['http://127.0.0.1:9000'], 'ipv6 loopback' => ['http://[::1]:9000'], 'a .localhost name' => ['http://sso.localhost']];
    }

    public function testAPublicHttpAddressIsRefused(): void
    {
        // Arrange
        $issuer = 'http://sso.example.com';

        // Act
        $problems = Checks::problems($issuer, false, false);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('not HTTPS', $problems[0]);
    }

    public function testAPublicHttpAddressIsAllowedWhenTheOperatorSaysSo(): void
    {
        // Arrange
        $issuer = 'http://sso.internal.example';

        // Act
        $problems = Checks::problems($issuer, false, true);

        // Assert
        $this->assertSame([], $problems);
    }

    public function testDemoDataOnAPublicAddressIsRefused(): void
    {
        // Arrange
        $issuer = 'https://sso.example.com';

        // Act
        $problems = Checks::problems($issuer, true, false);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('SSO_DEMO_DATA', $problems[0]);
    }

    public function testAnAddressThatIsNotAnAddressIsRefused(): void
    {
        // Arrange
        $issuer = 'sso.example.com';

        // Act
        $problems = Checks::problems($issuer, false, false);

        // Assert
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('SSO_PUBLIC_URL', $problems[0]);
    }
}
