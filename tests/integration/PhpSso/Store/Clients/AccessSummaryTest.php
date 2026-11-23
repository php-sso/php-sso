<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'accessSummary')]
final class AccessSummaryTest extends TestCase
{
    private function limited(): TestData
    {
        $data = new TestData();
        $alice = $data->user('alice');
        $staff = $data->group('Staff', [$alice]);
        $data->client('one', true);
        $data->client('two', true);
        $data->db->pdo->prepare('INSERT INTO client_group (client_id, group_id) VALUES (?, ?)')->execute(['one', $staff]);
        $data->db->pdo->prepare('INSERT INTO client_group (client_id, group_id) VALUES (?, ?)')->execute(['two', $staff]);
        $data->db->pdo->prepare('INSERT INTO client_user (client_id, user_sub) VALUES (?, ?)')->execute(['two', $alice]);

        return $data;
    }

    public function testItSummarisesEveryClientWithoutAFilter(): void
    {
        // Arrange
        $data = $this->limited();

        // Act
        $summary = $data->clients->accessSummary();

        // Assert
        $this->assertSame(['Staff'], $summary['one']['groups']);
        $this->assertSame(['Staff'], $summary['two']['groups']);
        $this->assertSame(1, $summary['two']['people']);
    }

    public function testItSummarisesOnlyTheClientsAskedFor(): void
    {
        // Arrange
        $data = $this->limited();

        // Act
        $summary = $data->clients->accessSummary(['two']);

        // Assert
        $this->assertSame(['two'], array_keys($summary));
        $this->assertSame(1, $summary['two']['people']);
    }
}
