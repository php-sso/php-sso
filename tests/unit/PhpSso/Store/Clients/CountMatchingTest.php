<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'countMatching')]
final class CountMatchingTest extends TestCase
{
    public function testItCountsAllClientsWithoutTextAndOnlyTheMatchesWithIt(): void
    {
        // Arrange
        $data = new TestData();
        foreach (['billing', 'billing-eu', 'wiki'] as $id) {
            $data->client($id);
        }

        // Act
        $all = $data->clients->countMatching('');
        $matching = $data->clients->countMatching('billing');

        // Assert
        $this->assertSame(3, $all);
        $this->assertSame(2, $matching);
    }
}
