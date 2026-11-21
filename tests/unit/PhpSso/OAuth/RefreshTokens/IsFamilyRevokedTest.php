<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteRefreshTokens::class, 'isFamilyRevoked')]
final class IsFamilyRevokedTest extends TestCase
{
    public function testItIsFalseForAFamilyNobodyRevoked(): void
    {
        // Arrange
        $store = new SqliteRefreshTokens(new Database(':memory:'));

        // Act
        $revoked = $store->isFamilyRevoked('family-1');

        // Assert
        self::assertFalse($revoked);
    }
}
