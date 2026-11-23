<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PhpSso\Store\DuplicateValue;
use PhpSso\Store\UnknownReference;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'create')]
final class CreateTest extends TestCase
{
    public function testARoleIsForEveryClientByDefault(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $id = $data->roles->create('editor', 'May edit content');

        // Assert
        $this->assertSame(
            ['id' => $id, 'name' => 'editor', 'description' => 'May edit content', 'all_clients' => true, 'client_count' => 0, 'group_count' => 0, 'user_count' => 0],
            $data->roles->find($id),
        );
    }

    public function testARoleCanBeLimitedToSomeClients(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');

        // Act
        $id = $data->roles->create('editor', '', false, ['app']);

        // Assert
        $this->assertSame(['app'], $data->roles->clientIds($id));
    }

    public function testTheNameIsUniqueWhateverTheCase(): void
    {
        // Arrange
        $data = new TestData();
        $data->roles->create('Editor', '');

        // Assert
        $this->expectException(DuplicateValue::class);

        // Act
        $data->roles->create('editor', '');
    }

    public function testALimitToAClientThatDoesNotExistIsRefused(): void
    {
        // Arrange
        $data = new TestData();

        // Assert
        $this->expectException(UnknownReference::class);

        // Act
        $data->roles->create('editor', '', false, ['missing']);
    }
}
