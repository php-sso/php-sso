<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'search')]
final class SearchTest extends TestCase
{
    public function testItMatchesNameUsernameAndEmailIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $data->user('bob');

        // Act
        $byName = $data->users->search('ALI', 10);
        $byEmail = $data->users->search('BOB@EXAMPLE', 10);

        // Assert
        $this->assertSame(['alice'], array_column($byName, 'username'));
        $this->assertSame(['bob'], array_column($byEmail, 'username'));
    }

    public function testAPercentSignInTheTextIsLiteral(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');

        // Act
        $found = $data->users->search('%', 10);

        // Assert
        $this->assertSame([], $found);
    }

    public function testItListsPeopleByNameIgnoringCaseAndStopsAtTheLimit(): void
    {
        // Arrange
        $data = new TestData();
        foreach (['carol', 'alice', 'bob'] as $username) {
            $data->user($username);
        }

        // Act
        $found = $data->users->search('@example.com', 2);

        // Assert
        $this->assertSame(['alice', 'bob'], array_column($found, 'username'));
    }
}
