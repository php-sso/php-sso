<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Clients;

use PhpSso\Store\Clients;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Clients::class, 'page')]
final class PageTest extends TestCase
{
    private function apps(): TestData
    {
        $data = new TestData();
        foreach (['zebra', 'apple', 'mango'] as $id) {
            $data->client($id);
        }

        return $data;
    }

    public function testItListsClientsByNameAndDecodesThem(): void
    {
        // Arrange
        $data = $this->apps();

        // Act
        $page = $data->clients->page('', 10, 0);

        // Assert
        $this->assertSame(['apple', 'mango', 'zebra'], array_column($page, 'client_id'));
        $this->assertSame(['http://localhost/cb'], $page[0]['redirect_uris']);
        $this->assertFalse($page[0]['confidential']);
    }

    public function testItReturnsOnlyTheRequestedPage(): void
    {
        // Arrange
        $data = $this->apps();

        // Act
        $page = $data->clients->page('', 1, 1);

        // Assert
        $this->assertSame(['mango'], array_column($page, 'client_id'));
    }

    public function testTextMatchesTheNameOrTheIdIgnoringCase(): void
    {
        // Arrange
        $data = $this->apps();

        // Act
        $byId = $data->clients->page('MAN', 10, 0);
        $byName = $data->clients->page('app zebra', 10, 0);

        // Assert
        $this->assertSame(['mango'], array_column($byId, 'client_id'));
        $this->assertSame(['zebra'], array_column($byName, 'client_id'));
    }
}
