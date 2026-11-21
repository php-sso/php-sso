<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Roles;

use PhpSso\Store\Roles;
use PhpSso\Store\DuplicateValue;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Roles::class, 'update')]
final class UpdateTest extends TestCase
{
    public function testItChangesTheNameAndDescription(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $id = $data->roles->create('editor', '');

        // Act
        $data->roles->update($id, 'writer', 'May write');

        // Assert
        $role = $data->roles->find($id);
        $this->assertSame(['writer', 'May write'], [$role['name'] ?? null, $role['description'] ?? null]);
    }

    public function testThePeopleWhoHadTheRoleKeepItUnderTheNewName(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $id = $data->roles->create('editor', '');
        $data->roles->assignUser($id, $alice);

        // Act
        $data->roles->update($id, 'writer', '');

        // Assert
        $this->assertSame(['writer'], $data->roles->effectiveFor($alice, 'app'));
    }

    public function testItRefusesANameAnotherRoleOfTheClientHas(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->roles->create('editor', '');
        $id = $data->roles->create('writer', '');

        // Assert
        $this->expectException(DuplicateValue::class);

        // Act
        $data->roles->update($id, 'Editor', '');
    }
}
