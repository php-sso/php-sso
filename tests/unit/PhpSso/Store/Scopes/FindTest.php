<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Scopes;

use PhpSso\Database;
use PhpSso\Store\Scopes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Scopes::class, 'find')]
final class FindTest extends TestCase
{
    public function testItFindsAnAddedScopeByItsExactName(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);
        $scopes->create('reports:read', 'Read reports');

        // Act
        $found = $scopes->find('reports:read');

        // Assert
        $this->assertSame(['name' => 'reports:read', 'description' => 'Read reports', 'builtin' => false], $found);
    }

    public function testItFindsABuiltInScopeToo(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);

        // Act
        $found = $scopes->find('openid');

        // Assert
        $this->assertNotNull($found);
        $this->assertTrue($found['builtin']);
    }

    public function testTheNameMustMatchExactlyIncludingCase(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);
        $scopes->create('reports:read', '');

        // Act
        $found = [$scopes->find('Reports:Read'), $scopes->find('nope')];

        // Assert
        $this->assertSame([null, null], $found);
    }
}
