<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sql\SqlAuthorizationCodes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(SqlAuthorizationCodes::class, 'save')]
final class SaveTest extends TestCase
{
    public function testItKeepsEveryFieldOfTheCodeIncludingWhenThePersonSignedIn(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(TestConnection::open());
        $code = new AuthorizationCodeRecord('hash-1', 'web', 'user-1', 'https://app/cb', ['openid', 'profile'], str_repeat('c', 43), 'nonce-1', 'session-1', 'family-1', 2_000_000_000, null, 1_999_999_000);

        // Act
        $store->save($code);

        // Assert
        self::assertEquals($code, $store->find('hash-1'));
    }

    public function testItKeepsOptionalFieldsAsNull(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(TestConnection::open());
        $code = new AuthorizationCodeRecord('hash-1', 'web', 'user-1', 'https://app/cb', ['openid'], str_repeat('c', 43), null, null, 'family-1', 2_000_000_000, null, null);

        // Act
        $store->save($code);

        // Assert
        $found = $store->find('hash-1');
        self::assertNull($found?->nonce);
        self::assertNull($found->sessionId);
        self::assertNull($found->authTime);
    }
}
