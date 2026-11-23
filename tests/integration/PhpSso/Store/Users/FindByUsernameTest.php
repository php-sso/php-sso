<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'findByUsername')]
final class FindByUsernameTest extends TestCase
{
    public function testItFindsThePersonIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');

        // Act
        $found = $data->users->findByUsername('ALICE');

        // Assert
        $this->assertSame($sub, $found['sub'] ?? null);
    }

    public function testItFindsNoOneWhenTheNameIsUnknown(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');

        // Act
        $found = $data->users->findByUsername('bob');

        // Assert
        $this->assertNull($found);
    }
}
