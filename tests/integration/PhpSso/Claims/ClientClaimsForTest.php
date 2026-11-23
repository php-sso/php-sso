<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Claims;

use PhpSso\Claims;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Claims::class, 'clientClaimsFor')]
final class ClientClaimsForTest extends TestCase
{
    public function testItReleasesThePersonsRolesInTheClientForTheRolesScope(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->roles->assignGroup($data->roles->create('editor', ''), $data->group('staff', [$alice]));
        $data->roles->assignUser($data->roles->create('admin', ''), $alice);
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'roles' => []]);

        // Act
        $released = $claims->clientClaimsFor('app', $alice, ['openid', 'roles']);

        // Assert
        $this->assertSame(['roles' => ['admin', 'editor']], $released);
    }

    public function testItReleasesAnEmptyListForAPersonWithoutRoles(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'roles' => []]);

        // Act
        $released = $claims->clientClaimsFor('app', $alice, ['roles']);

        // Assert
        $this->assertSame(['roles' => []], $released);
    }

    public function testItReleasesNothingWithoutTheRolesScope(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('admin', ''), $alice);
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'roles' => []]);

        // Act
        $released = $claims->clientClaimsFor('app', $alice, ['openid', 'profile']);

        // Assert
        $this->assertSame([], $released);
    }

    public function testItOnlyReleasesTheRolesAvailableInTheClientThatAsks(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $data->client('other');
        $alice = $data->user('alice');
        $data->roles->assignUser($data->roles->create('admin', '', false, ['other']), $alice);
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'roles' => []]);

        // Act
        $released = $claims->clientClaimsFor('app', $alice, ['roles']);

        // Assert
        $this->assertSame(['roles' => []], $released);
    }
}
