<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlRefreshTokens::class, 'isFamilyRevoked')]
final class IsFamilyRevokedTest extends TestCase
{
    public function testItIsFalseForAFamilyNobodyRevoked(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(Connection::sqlite(':memory:'));

        // Act
        $revoked = $store->isFamilyRevoked('family-1');

        // Assert
        self::assertFalse($revoked);
    }
}
