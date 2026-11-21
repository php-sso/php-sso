<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Identifier\UsernameOrEmailResolver;

use PhpSso\Auth\Identifier\UsernameOrEmailResolver;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(UsernameOrEmailResolver::class, 'find')]
#[CoversMethod(UsernameOrEmailResolver::class, 'normalize')]
final class FindTest extends TestCase
{
    public function testItFindsAUserByUsername(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $resolver = new UsernameOrEmailResolver($data->users);

        // Act
        $user = $resolver->find('Alice');

        // Assert
        $this->assertSame($sub, $user['sub'] ?? null);
    }

    public function testItFindsAUserByEmailAddress(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $resolver = new UsernameOrEmailResolver($data->users);

        // Act
        $user = $resolver->find(' Alice@Example.com ');

        // Assert
        $this->assertSame($sub, $user['sub'] ?? null);
    }

    public function testItPrefersTheEmailOwnerOverAUsernameThatLooksLikeTheAddress(): void
    {
        // Arrange
        $data = new TestData();
        $owner = $data->user('alice');
        $data->users->create(['username' => 'alice@example.com', 'password' => 'secret-pass', 'name' => 'Imposter', 'email' => 'other@example.com', 'email_verified' => false, 'is_admin' => false]);
        $resolver = new UsernameOrEmailResolver($data->users);

        // Act
        $user = $resolver->find('alice@example.com');

        // Assert
        $this->assertSame($owner, $user['sub'] ?? null);
    }

    public function testItFallsBackToAUsernameThatContainsAnAtSign(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->users->create(['username' => 'bob@corp', 'password' => 'secret-pass', 'name' => 'Bob', 'email' => 'bob@example.com', 'email_verified' => true, 'is_admin' => false]);
        $resolver = new UsernameOrEmailResolver($data->users);

        // Act
        $user = $resolver->find('bob@corp');

        // Assert
        $this->assertSame($sub, $user['sub'] ?? null);
    }
}
