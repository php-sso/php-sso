<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\PasswordResets;

use PhpSso\Store\PasswordResets;
use PhpSso\Store\Sql\SqlPasswordResets;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(PasswordResets::class, 'issue')]
#[CoversMethod(PasswordResets::class, 'find')]
final class IssueTest extends TestCase
{
    public function testAnIssuedTokenIsFoundByItsHashUntilItExpires(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'hash-1', 2000, '203.0.113.5', 1000);

        // Act
        $found = $resets->find('hash-1', 1999);
        $expired = $resets->find('hash-1', 2000);
        $unknown = $resets->find('other', 1500);

        // Assert
        $this->assertSame('sub-1', $found['user_sub'] ?? null);
        $this->assertNull($expired);
        $this->assertNull($unknown);
    }

    public function testANewTokenReplacesTheEarlierOneOfThatPersonOnly(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'old', 5000, '203.0.113.5', 1000);
        $resets->issue('sub-2', 'other-person', 5000, '203.0.113.5', 1000);

        // Act
        $resets->issue('sub-1', 'new', 5000, '203.0.113.5', 1001);

        // Assert
        $this->assertNull($resets->find('old', 1002));
        $this->assertNotNull($resets->find('new', 1002));
        $this->assertNotNull($resets->find('other-person', 1002));
    }

    public function testTheRequestThatReplacedAnotherStillCounts(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'old', 5000, '203.0.113.5', 1000);

        // Act
        $resets->issue('sub-1', 'new', 5000, '203.0.113.5', 1001);

        // Assert
        $this->assertSame(2, $resets->requestsFor('sub-1', 0));
    }
}
