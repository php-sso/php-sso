<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Database;
use PhpSso\OAuth\RefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(RefreshTokens::class, 'isFamilyRevoked')]
final class IsFamilyRevokedTest extends TestCase
{
    public function testItIsFalseForAFamilyNobodyRevoked(): void
    {
        // Arrange
        $store = new RefreshTokens(new Database(':memory:'));

        // Act
        $revoked = $store->isFamilyRevoked('family-1');

        // Assert
        self::assertFalse($revoked);
    }
}
