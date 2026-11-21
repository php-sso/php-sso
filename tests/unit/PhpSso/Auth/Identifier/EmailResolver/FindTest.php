<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Identifier\EmailResolver;

use PhpSso\Auth\Identifier\EmailResolver;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(EmailResolver::class, 'find')]
final class FindTest extends TestCase
{
    public function testItFindsTheUserIgnoringCaseAndSpaces(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $resolver = new EmailResolver($data->users);

        // Act
        $user = $resolver->find(' ALICE@example.com ');

        // Assert
        $this->assertSame($sub, $user['sub'] ?? null);
    }

    public function testItDoesNotMatchAUsername(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $resolver = new EmailResolver($data->users);

        // Act
        $user = $resolver->find('alice');

        // Assert
        $this->assertNull($user);
    }

    public function testItMatchesNoOneWhenTwoPeopleShareTheAddress(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $data->db->pdo->prepare('INSERT INTO users (sub, username, password_hash, name, email) VALUES (?, ?, ?, ?, ?)')
            ->execute(['sub-2', 'alice2', 'x', 'Alice Two', 'Alice@Example.com']);
        $resolver = new EmailResolver($data->users);

        // Act
        $user = $resolver->find('alice@example.com');

        // Assert
        $this->assertNull($user);
    }
}
