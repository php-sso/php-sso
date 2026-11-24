<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RefreshTokens;

use PhpSso\OAuthServer\Grant\RefreshTokenRecord;
use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlRefreshTokens::class, 'save')]
#[CoversMethod(SqlRefreshTokens::class, 'find')]
final class ResourcesTest extends TestCase
{
    /** @param list<string> $resources */
    private function token(array $resources): RefreshTokenRecord
    {
        return new RefreshTokenRecord('hash-1', 'family-1', 'web', 'user-1', ['openid'], 'session-1', 2_000_000_000, null, 1_999_999_000, null, $resources);
    }

    public function testTheResourcesComeBackWithTheToken(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(TestConnection::open());

        // Act
        $store->save($this->token(['https://mcp.example/']));

        // Assert
        self::assertSame(['https://mcp.example/'], $store->find('hash-1')?->resources);
    }

    public function testNoResourcesComeBackAsAnEmptyList(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(TestConnection::open());

        // Act
        $store->save($this->token([]));

        // Assert
        self::assertSame([], $store->find('hash-1')?->resources);
    }
}
