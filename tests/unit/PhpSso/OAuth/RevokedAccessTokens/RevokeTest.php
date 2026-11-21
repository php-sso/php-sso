<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RevokedAccessTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\OAuth\RevokedAccessTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(RevokedAccessTokens::class, 'revoke')]
final class RevokeTest extends TestCase
{
    public function testItRemembersTheTokenAndToleratesRevokingItTwice(): void
    {
        // Arrange
        $store = new RevokedAccessTokens(new Database(':memory:'));

        // Act
        $store->revoke('jti-1', 2_000_000_000);
        $store->revoke('jti-1', 2_000_000_000);

        // Assert
        self::assertTrue($store->isRevoked('jti-1'));
    }
}
