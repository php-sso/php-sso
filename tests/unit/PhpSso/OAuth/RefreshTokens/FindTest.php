<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlRefreshTokens::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownToken(): void
    {
        // Arrange
        $store = new SqlRefreshTokens(Connection::sqlite(':memory:'));

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
