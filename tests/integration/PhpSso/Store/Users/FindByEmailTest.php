<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'findByEmail')]
final class FindByEmailTest extends TestCase
{
    public function testItReturnsNullForAnUnknownAddress(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');

        // Act
        $user = $data->users->findByEmail('nobody@example.com');

        // Assert
        $this->assertNull($user);
    }
}
