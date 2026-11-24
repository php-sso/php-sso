<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;
use PhpSso\Store\Sql\SqlAuthorizationCodes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlAuthorizationCodes::class, 'save')]
#[CoversMethod(SqlAuthorizationCodes::class, 'find')]
#[CoversMethod(SqlAuthorizationCodes::class, 'markUsed')]
final class ResourcesTest extends TestCase
{
    /** @param list<string> $resources */
    private function code(array $resources): AuthorizationCodeRecord
    {
        return new AuthorizationCodeRecord('hash-1', 'web', 'user-1', 'https://app/cb', ['openid'], str_repeat('c', 43), null, 'session-1', 'family-1', 2_000_000_000, null, 1_999_999_000, null, $resources);
    }

    public function testTheResourcesComeBackWithTheCode(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(TestConnection::open());

        // Act
        $store->save($this->code(['https://mcp.example/', 'https://files.example/api']));

        // Assert
        self::assertSame(['https://mcp.example/', 'https://files.example/api'], $store->find('hash-1')?->resources);
    }

    public function testNoResourcesComeBackAsAnEmptyList(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(TestConnection::open());

        // Act
        $store->save($this->code([]));

        // Assert
        self::assertSame([], $store->find('hash-1')?->resources);
    }
}
