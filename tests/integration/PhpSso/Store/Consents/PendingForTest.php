<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Consents;

use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlConsents;

#[CoversMethod(Consents::class, 'pendingFor')]
#[CoversMethod(Consents::class, 'create')]
final class PendingForTest extends TestCase
{
    public function testANewConsentIsPendingForEveryone(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', 'https://example.com/terms', true);
        $sub = $data->user('alice');

        // Act
        $pending = $consents->pendingFor($sub);

        // Assert
        $this->assertSame([$id], array_map(static fn (array $c): int => (int) $c['id'], $pending));
    }

    public function testASwitchedOffConsentIsNotPending(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $consents->create('Terms', 'Please read.', null, false);
        $sub = $data->user('alice');

        // Act
        $pending = $consents->pendingFor($sub);

        // Assert
        $this->assertSame([], $pending);
    }

    public function testAnAcceptedConsentIsNotPendingAnyMore(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $sub = $data->user('alice');
        $consents->accept($sub, $id, 1, '203.0.113.9');

        // Act
        $pending = $consents->pendingFor($sub);

        // Assert
        $this->assertSame([], $pending);
    }

    public function testConsentsComeInTheOrderTheyWereAdded(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $first = $consents->create('Terms', 'a', null, true);
        $second = $consents->create('Policy', 'b', null, true);
        $sub = $data->user('alice');

        // Act
        $pending = $consents->pendingFor($sub);

        // Assert
        $this->assertSame([$first, $second], array_map(static fn (array $c): int => (int) $c['id'], $pending));
    }
}
