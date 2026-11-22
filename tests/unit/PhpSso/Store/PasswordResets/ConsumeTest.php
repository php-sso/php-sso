<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\PasswordResets;

use PhpSso\Store\PasswordResets;
use PhpSso\Store\Sql\SqlPasswordResets;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(PasswordResets::class, 'consume')]
final class ConsumeTest extends TestCase
{
    public function testATokenCanBeUsedOnceAndThenItIsGone(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'hash-1', 5000, '203.0.113.5', 1000);
        $id = $resets->find('hash-1', 1001)['id'];

        // Act
        $first = $resets->consume($id, 1001);
        $second = $resets->consume($id, 1002);

        // Assert
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertNull($resets->find('hash-1', 1003));
    }

    public function testAnExpiredTokenCannotBeUsed(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'hash-1', 2000, '203.0.113.5', 1000);
        $id = (int) $resets->find('hash-1', 1500)['id'];

        // Act
        $used = $resets->consume($id, 2000);

        // Assert
        $this->assertFalse($used);
    }

    public function testATokenThatWasReplacedCannotBeUsed(): void
    {
        // Arrange
        $resets = new SqlPasswordResets(TestConnection::open());
        $resets->issue('sub-1', 'old', 5000, '203.0.113.5', 1000);
        $oldId = $resets->find('old', 1001)['id'];
        $resets->issue('sub-1', 'new', 5000, '203.0.113.5', 1002);

        // Act
        $used = $resets->consume($oldId, 1003);

        // Assert
        $this->assertFalse($used);
    }
}
