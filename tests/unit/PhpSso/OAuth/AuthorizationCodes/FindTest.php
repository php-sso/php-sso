<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\Sqlite\SqliteAuthorizationCodes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteAuthorizationCodes::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new SqliteAuthorizationCodes(new Database(':memory:'));

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
