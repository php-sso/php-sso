<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteAuthorizationCodes;
use PhpSso\OAuthServer\Grant\AuthorizationCodeRecord;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteAuthorizationCodes::class, 'save')]
#[CoversMethod(SqliteAuthorizationCodes::class, 'find')]
#[CoversMethod(SqliteAuthorizationCodes::class, 'markUsed')]
final class AmrTest extends TestCase
{
    private function code(?array $amr): AuthorizationCodeRecord
    {
        return new AuthorizationCodeRecord('hash-1', 'web', 'user-1', 'https://app/cb', ['openid'], str_repeat('c', 43), null, 'session-1', 'family-1', 2_000_000_000, null, 1_999_999_000, $amr);
    }

    public function testTheMethodsComeBackWithTheCode(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));

        // Act
        $store->save($this->code(['pwd', 'otp', 'mfa']));

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $store->find('hash-1')?->amr);
    }

    public function testNoMethodsStayNull(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));

        // Act
        $store->save($this->code(null));

        // Assert
        self::assertNull($store->find('hash-1')?->amr);
    }

    public function testUsingTheCodeKeepsTheMethods(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));
        $store->save($this->code(['pwd']));

        // Act
        $store->markUsed('hash-1', 1_999_999_500);

        // Assert
        self::assertSame(['pwd'], $store->find('hash-1')?->amr);
    }
}
