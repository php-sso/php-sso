<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\SsoClient;

use PhpSso\OAuth\SsoClient;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SsoClient::class, 'fromRow')]
final class FromRowTest extends TestCase
{
    /**
     * @param list<string>|null $scopes
     * @return array<string, mixed>
     */
    private function row(?array $scopes): array
    {
        return ['client_id' => 'web', 'name' => 'Web', 'redirect_uris' => [], 'confidential' => true, 'grants' => [], 'secret_hash' => null, 'scopes' => $scopes, 'backchannel_logout_uri' => null, 'post_logout_redirect_uris' => []];
    }

    public function testAClientWithAListIsLimitedToItWhateverTheDefaultIs(): void
    {
        // Arrange
        $row = $this->row(['openid']);

        // Act
        $client = SsoClient::fromRow($row, ['openid', 'email']);

        // Assert
        self::assertTrue($client->allowsScope('openid'));
        self::assertFalse($client->allowsScope('email'));
    }

    public function testAClientWithNoListGetsTheDefaultOnly(): void
    {
        // Arrange
        $row = $this->row(null);

        // Act
        $client = SsoClient::fromRow($row, ['openid', 'email']);

        // Assert
        self::assertTrue($client->allowsScope('email'));
        self::assertFalse($client->allowsScope('reports:read'));
    }

    public function testWithoutADefaultAClientWithNoListMayAskForAnything(): void
    {
        // Arrange
        $row = $this->row(null);

        // Act
        $client = SsoClient::fromRow($row);

        // Assert
        self::assertTrue($client->allowsScope('reports:read'));
    }
}
