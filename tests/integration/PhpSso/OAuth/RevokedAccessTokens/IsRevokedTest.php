<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RevokedAccessTokens;

use PhpSso\Store\Sql\SqlRevokedAccessTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlRevokedAccessTokens::class, 'isRevoked')]
final class IsRevokedTest extends TestCase
{
    public function testItIsFalseForATokenNobodyRevoked(): void
    {
        // Arrange
        $store = new SqlRevokedAccessTokens(TestConnection::open());
        $store->revoke('jti-1', 2_000_000_000);

        // Act
        $revoked = $store->isRevoked('jti-2');

        // Assert
        self::assertFalse($revoked);
    }
}
