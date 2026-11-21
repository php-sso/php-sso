<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RevokedAccessTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteRevokedAccessTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteRevokedAccessTokens::class, 'revoke')]
final class RevokeTest extends TestCase
{
    public function testItRemembersTheTokenAndToleratesRevokingItTwice(): void
    {
        // Arrange
        $store = new SqliteRevokedAccessTokens(new Database(':memory:'));

        // Act
        $store->revoke('jti-1', 2_000_000_000);
        $store->revoke('jti-1', 2_000_000_000);

        // Assert
        self::assertTrue($store->isRevoked('jti-1'));
    }
}
