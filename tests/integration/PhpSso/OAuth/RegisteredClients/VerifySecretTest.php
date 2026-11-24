<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RegisteredClients;

use PhpSso\OAuth\RegisteredClients;
use PhpSso\OAuth\SsoClient;
use PhpSso\OAuthServer\Token\TokenHash;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(RegisteredClients::class, 'verifySecret')]
final class VerifySecretTest extends TestCase
{
    private function client(?string $hash): SsoClient
    {
        return new SsoClient('app', 'App', [], true, ['authorization_code'], $hash, null, null, []);
    }

    public function testItChecksAnAdministratorsPasswordHash(): void
    {
        // Arrange
        $clients = new RegisteredClients((new TestData())->clients);
        $client = $this->client(password_hash('s3cret', PASSWORD_DEFAULT));

        // Act
        $results = [$clients->verifySecret($client, 's3cret'), $clients->verifySecret($client, 'wrong')];

        // Assert
        self::assertSame([true, false], $results);
    }

    public function testItChecksTheSha256OfAClientThatRegisteredItself(): void
    {
        // Arrange
        $clients = new RegisteredClients((new TestData())->clients);
        $client = $this->client(TokenHash::of('random-secret'));

        // Act
        $results = [$clients->verifySecret($client, 'random-secret'), $clients->verifySecret($client, 'wrong')];

        // Assert
        self::assertSame([true, false], $results);
    }

    public function testAClientWithoutASecretNeverMatches(): void
    {
        // Arrange
        $clients = new RegisteredClients((new TestData())->clients);

        // Act
        $result = $clients->verifySecret($this->client(null), '');

        // Assert
        self::assertFalse($result);
    }
}
