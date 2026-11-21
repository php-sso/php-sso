<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlRefreshTokens::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownToken(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(TestConnection::open());

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
