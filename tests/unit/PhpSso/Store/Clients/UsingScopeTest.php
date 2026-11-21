<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'usingScope')]
final class UsingScopeTest extends TestCase
{
    /** @param list<string>|null $scopes */
    private function client(Clients $clients, string $id, ?array $scopes): void
    {
        $clients->insert(['client_id' => $id, 'name' => 'App ' . $id, 'secret_hash' => null, 'confidential' => false, 'redirect_uris' => ['https://app.test/cb'], 'grants' => ['authorization_code'], 'scopes' => $scopes]);
    }

    public function testItListsTheClientsThatMayAskForTheScope(): void
    {
        // Arrange
        $clients = (new TestData())->clients;
        $this->client($clients, 'a', ['openid', 'reports:read']);
        $this->client($clients, 'b', ['openid']);
        $this->client($clients, 'c', ['reports:read']);

        // Act
        $using = $clients->usingScope('reports:read');

        // Assert
        $this->assertSame(['a', 'c'], array_column($using, 'client_id'));
        $this->assertSame(['App a', 'App c'], array_column($using, 'name'));
    }

    public function testAClientWithNoListOfItsOwnNeverCounts(): void
    {
        // Arrange
        $clients = (new TestData())->clients;
        $this->client($clients, 'old', null);

        // Act
        $using = $clients->usingScope('reports:read');

        // Assert
        $this->assertSame([], $using);
    }
}
