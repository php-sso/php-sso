<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlRefreshTokens::class, 'revokeFamily')]
final class RevokeFamilyTest extends TestCase
{
    public function testItRevokesOneFamilyOnly(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(Connection::sqlite(':memory:'));

        // Act
        $store->revokeFamily('family-1', 1_700_000_000);

        // Assert
        self::assertTrue($store->isFamilyRevoked('family-1'));
        self::assertFalse($store->isFamilyRevoked('family-2'));
    }

    public function testRevokingTwiceIsHarmless(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(Connection::sqlite(':memory:'));
        $store->revokeFamily('family-1', 1_700_000_000);

        // Act
        $store->revokeFamily('family-1', 1_700_000_500);

        // Assert
        self::assertTrue($store->isFamilyRevoked('family-1'));
    }
}
