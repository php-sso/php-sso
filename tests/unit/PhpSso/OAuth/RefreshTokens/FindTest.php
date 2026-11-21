<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteRefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteRefreshTokens::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownToken(): void
    {
        // Arrange
        $store = new SqliteRefreshTokens(new Database(':memory:'));

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
