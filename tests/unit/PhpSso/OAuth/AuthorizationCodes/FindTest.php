<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\AuthorizationCodes;

use PhpSso\Store\Sql\SqlAuthorizationCodes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(SqlAuthorizationCodes::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownCode(): void
    {
        // Arrange
        $store = new SqlAuthorizationCodes(TestConnection::open());

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
