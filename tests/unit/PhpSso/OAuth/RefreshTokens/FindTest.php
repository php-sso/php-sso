<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\OAuth\RefreshTokens;

use PhpSso\Database;
use PhpSso\OAuth\RefreshTokens;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(RefreshTokens::class, 'find')]
final class FindTest extends TestCase
{
    public function testItKnowsNothingOfAnUnknownToken(): void
    {
        // Arrange
        $store = new RefreshTokens(new Database(':memory:'));

        // Act
        $found = $store->find('nope');

        // Assert
        self::assertNull($found);
    }
}
