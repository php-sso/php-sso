<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Database;
use PhpSso\OAuth\RefreshTokens;
use PhpSso\OAuthServer\Grant\RefreshTokenRecord;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(RefreshTokens::class, 'save')]
#[CoversMethod(RefreshTokens::class, 'find')]
final class AmrTest extends TestCase
{
    public function testTheMethodsComeBackWithTheToken(): void
    {
        // Arrange
        $store = new RefreshTokens(new Database(':memory:'));

        // Act
        $store->save(new RefreshTokenRecord('rt-1', 'family-1', 'web', 'user-1', ['openid'], 'session-1', 2_000_000_000, null, 1_999_999_000, ['pwd', 'mfa']));

        // Assert
        self::assertSame(['pwd', 'mfa'], $store->find('rt-1')?->amr);
    }

    public function testNoMethodsStayNull(): void
    {
        // Arrange
        $store = new RefreshTokens(new Database(':memory:'));

        // Act
        $store->save(new RefreshTokenRecord('rt-1', 'family-1', 'web', 'user-1', ['openid'], 'session-1', 2_000_000_000, null, 1_999_999_000));

        // Assert
        self::assertNull($store->find('rt-1')?->amr);
    }
}
