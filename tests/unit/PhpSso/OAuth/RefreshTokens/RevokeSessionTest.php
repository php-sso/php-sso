<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\OAuth\AuthorizationCodes;
use PhpSso\OAuth\RefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;
use PhpSso\OAuthServer\Grant\RefreshTokenRecord;

#[CoversMethod(RefreshTokens::class, 'revokeSession')]
final class RevokeSessionTest extends TestCase
{
    public function testItRevokesEveryFamilyOfTheSessionIncludingOnesWithoutARefreshToken(): void
    {
        // Arrange
        $db = new Database(':memory:');
        $store = new RefreshTokens($db);
        $store->save($this->token('rt-1', 'family-with-refresh', 'session-1'));
        $store->save($this->token('rt-2', 'family-other-session', 'session-2'));
        (new AuthorizationCodes($db))->save(new AuthorizationCodeRecord('code-1', 'spa', 'user-1', 'https://app/cb', ['openid'], str_repeat('c', 43), null, 'session-1', 'family-code-only', 2_000_000_000, null, null));

        // Act
        $store->revokeSession('session-1', 1_700_000_000);

        // Assert
        self::assertTrue($store->isFamilyRevoked('family-with-refresh'));
        self::assertTrue($store->isFamilyRevoked('family-code-only'));
        self::assertFalse($store->isFamilyRevoked('family-other-session'));
    }

    private function token(string $hash = 'rt-1', string $family = 'family-1', ?string $session = 'session-1'): RefreshTokenRecord
    {
        return new RefreshTokenRecord($hash, $family, 'web', 'user-1', ['openid'], $session, 2_000_000_000, null, 1_999_999_000);
    }
}
