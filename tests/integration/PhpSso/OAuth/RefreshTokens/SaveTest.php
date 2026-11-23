<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\RefreshTokenRecord;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlRefreshTokens::class, 'save')]
final class SaveTest extends TestCase
{
    public function testItKeepsEveryFieldIncludingWhenThePersonSignedIn(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(TestConnection::open());
        $token = $this->token();

        // Act
        $store->save($token);

        // Assert
        self::assertEquals($token, $store->find('rt-1'));
    }

    private function token(string $hash = 'rt-1', string $family = 'family-1', ?string $session = 'session-1'): RefreshTokenRecord
    {
        return new RefreshTokenRecord($hash, $family, 'web', 'user-1', ['openid'], $session, 2_000_000_000, null, 1_999_999_000);
    }
}
