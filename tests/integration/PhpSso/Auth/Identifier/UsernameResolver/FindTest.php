<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Identifier\UsernameResolver;

use PhpSso\Auth\Identifier\UsernameResolver;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(UsernameResolver::class, 'find')]
final class FindTest extends TestCase
{
    public function testItFindsTheUserIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $resolver = new UsernameResolver($data->users);

        // Act
        $user = $resolver->find('ALICE');

        // Assert
        $this->assertSame($sub, $user['sub'] ?? null);
    }

    public function testItDoesNotMatchAnEmailAddress(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $resolver = new UsernameResolver($data->users);

        // Act
        $user = $resolver->find('alice@example.com');

        // Assert
        $this->assertNull($user);
    }
}
