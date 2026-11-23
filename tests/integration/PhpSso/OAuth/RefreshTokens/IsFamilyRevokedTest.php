<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlRefreshTokens::class, 'isFamilyRevoked')]
final class IsFamilyRevokedTest extends TestCase
{
    public function testItIsFalseForAFamilyNobodyRevoked(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(TestConnection::open());

        // Act
        $revoked = $store->isFamilyRevoked('family-1');

        // Assert
        self::assertFalse($revoked);
    }
}
