<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'setRegistrar')]
#[CoversMethod(Clients::class, 'anyRegistrar')]
final class SetRegistrarTest extends TestCase
{
    public function testAClientIsNoRegistrarUntilItIsMadeOne(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');

        // Act
        $row = $data->clients->find('backend');

        // Assert
        $this->assertNotNull($row);
        $this->assertArrayHasKey('registrar', $row);
        $this->assertNull($row['registrar']);
        $this->assertFalse($data->clients->anyRegistrar());
    }

    public function testItStoresTheScopesTheRegisteredAppsMayGet(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');

        // Act
        $data->clients->setRegistrar('backend', ['openid', 'profile']);

        // Assert
        $this->assertSame(['scopes' => ['openid', 'profile'], 'resources' => []], $data->clients->find('backend')['registrar'] ?? null);
        $this->assertTrue($data->clients->anyRegistrar());
    }

    public function testNullTakesTheRightAway(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $data->clients->setRegistrar('backend', ['openid']);

        // Act
        $data->clients->setRegistrar('backend', null);

        // Assert
        $row = $data->clients->find('backend');
        $this->assertNotNull($row);
        $this->assertNull($row['registrar']);
        $this->assertFalse($data->clients->anyRegistrar());
    }

    public function testItStoresTheResourcesTheRegisteredAppsMayGet(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');

        // Act
        $data->clients->setRegistrar('backend', ['openid'], ['https://mcp.example/']);

        // Assert
        $this->assertSame(['https://mcp.example/'], $data->clients->find('backend')['registrar']['resources'] ?? null);
    }
}
