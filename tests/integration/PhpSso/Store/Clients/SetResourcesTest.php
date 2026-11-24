<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'setResources')]
final class SetResourcesTest extends TestCase
{
    public function testAClientHasNoResourcesUntilItIsGivenSome(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('web');

        // Act
        $row = $data->clients->find('web');

        // Assert
        $this->assertSame([], $row['resources'] ?? null);
    }

    public function testItStoresTheListAndCanEmptyIt(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('web');

        // Act
        $data->clients->setResources('web', ['https://api.example/', 'https://files.example/api']);
        $stored = $data->clients->find('web')['resources'] ?? null;
        $data->clients->setResources('web', []);

        // Assert
        $this->assertSame(['https://api.example/', 'https://files.example/api'], $stored);
        $this->assertSame([], $data->clients->find('web')['resources'] ?? null);
    }
}
