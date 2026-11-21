<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\RefreshTokenRecord;

#[CoversMethod(SqliteRefreshTokens::class, 'markUsed')]
final class MarkUsedTest extends TestCase
{
    public function testAnExchangedTokenCannotBeExchangedAgain(): void
    {
        // Arrange
        $store = new SqliteRefreshTokens(new Database(':memory:'));
        $store->save($this->token());

        // Act
        $first = $store->markUsed('rt-1', 1_700_000_000);
        $second = $store->markUsed('rt-1', 1_700_000_001);

        // Assert
        self::assertTrue($first);
        self::assertFalse($second);
    }

    private function token(string $hash = 'rt-1', string $family = 'family-1', ?string $session = 'session-1'): RefreshTokenRecord
    {
        return new RefreshTokenRecord($hash, $family, 'web', 'user-1', ['openid'], $session, 2_000_000_000, null, 1_999_999_000);
    }
}
