<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\SqlAuthorizationCodes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqlAuthorizationCodes::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(Connection::sqlite(':memory:'));

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
