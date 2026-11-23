<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Scopes;

use PhpSso\Store\Scopes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\Sql\SqlScopes;
use Tests\integration\PhpSso\Support\TestConnection;

#[CoversMethod(Scopes::class, 'all')]
#[CoversMethod(Scopes::class, 'names')]
#[CoversMethod(Scopes::class, 'builtinNames')]
final class AllTest extends TestCase
{
    private function scopes(): Scopes
    {
        return new SqlScopes(TestConnection::open(), ['openid', 'profile', 'roles']);
    }

    public function testTheBuiltInScopesComeFirstWithTheirMeaning(): void
    {
        // Arrange
        $scopes = $this->scopes();

        // Act
        $all = $scopes->all();

        // Assert
        $this->assertSame(['openid', 'profile', 'roles'], array_column($all, 'name'));
        $this->assertSame([true, true, true], array_column($all, 'builtin'));
        $this->assertSame('Their name.', $all[1]['description']);
    }

    public function testAddedScopesFollowByNameAndAreOffered(): void
    {
        // Arrange
        $scopes = $this->scopes();
        $scopes->create('reports:read', 'Read reports');
        $scopes->create('Invoices:write', '');

        // Act
        $names = $scopes->names();
        $all = $scopes->all();

        // Assert
        $this->assertSame(['openid', 'profile', 'roles', 'Invoices:write', 'reports:read'], $names);
        $this->assertSame(['openid', 'profile', 'roles'], $scopes->builtinNames());
        $this->assertSame([false, 'Read reports'], [$all[4]['builtin'], $all[4]['description']]);
    }

    public function testAnAddedScopeIsKeptBetweenRequests(): void
    {
        // Arrange
        $db = TestConnection::open();
        (new SqlScopes($db, ['openid']))->create('reports:read', '');

        // Act
        $names = (new SqlScopes($db, ['openid']))->names();

        // Assert
        $this->assertSame(['openid', 'reports:read'], $names);
    }
}
