<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteAuthorizationCodes;
use PhpSso\Store\Sqlite\SqliteRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;

#[CoversMethod(SqliteAuthorizationCodes::class, 'markUsed')]
final class MarkUsedTest extends TestCase
{
    private function saved(SqliteAuthorizationCodes $store, string $family = 'family-1'): void
    {
        $store->save(new AuthorizationCodeRecord('hash-1', 'web', 'user-1', 'https://app/cb', ['openid'], str_repeat('c', 43), null, null, $family, 2_000_000_000, null, null));
    }

    public function testExactlyOneCallerRedeemsACode(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));
        $this->saved($store);

        // Act
        $first = $store->markUsed('hash-1', 1_700_000_000);
        $second = $store->markUsed('hash-1', 1_700_000_001);

        // Assert
        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame(1_700_000_000, $store->find('hash-1')?->usedAt);
    }

    public function testACodeWhoseSessionEndedMeanwhileCannotBeRedeemed(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $store = new SqliteAuthorizationCodes($db);
        $this->saved($store, 'family-9');
        (new SqliteRefreshTokens($db))->revokeFamily('family-9', 1_700_000_000);

        // Act
        $redeemed = $store->markUsed('hash-1', 1_700_000_001);

        // Assert
        self::assertFalse($redeemed);
    }

    public function testItRefusesAnUnknownCode(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));

        // Act
        $redeemed = $store->markUsed('nope', 1_700_000_000);

        // Assert
        self::assertFalse($redeemed);
    }
}
