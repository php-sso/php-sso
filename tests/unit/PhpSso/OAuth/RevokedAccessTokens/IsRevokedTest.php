<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RevokedAccessTokens;

use PhpSso\Database;
use PhpSso\OAuth\RevokedAccessTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(RevokedAccessTokens::class, 'isRevoked')]
final class IsRevokedTest extends TestCase
{
    public function testItIsFalseForATokenNobodyRevoked(): void
    {
        // Arrange
        $store = new RevokedAccessTokens(new Database(':memory:'));
        $store->revoke('jti-1', 2_000_000_000);

        // Act
        $revoked = $store->isRevoked('jti-2');

        // Assert
        self::assertFalse($revoked);
    }
}
