<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Scopes;

use PhpSso\Database;
use PhpSso\Store\Scopes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Scopes::class, 'delete')]
#[CoversMethod(Scopes::class, 'updateDescription')]
final class DeleteTest extends TestCase
{
    public function testAnAddedScopeCanBeRemoved(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);
        $scopes->create('reports:read', '');

        // Act
        $removed = $scopes->delete('reports:read');

        // Assert
        $this->assertTrue($removed);
        $this->assertSame(['openid'], $scopes->names());
    }

    public function testABuiltInScopeCannotBeRemoved(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid', 'email']);

        // Act
        $removed = $scopes->delete('email');

        // Assert
        $this->assertFalse($removed);
        $this->assertSame(['openid', 'email'], $scopes->names());
    }

    public function testRemovingSomethingThatIsNotThereIsFalse(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);

        // Act
        $removed = $scopes->delete('nope');

        // Assert
        $this->assertFalse($removed);
    }

    public function testTheMeaningOfAnAddedScopeCanBeChangedButNotOfABuiltInOne(): void
    {
        // Arrange
        $scopes = new Scopes(new Database(':memory:'), ['openid']);
        $scopes->create('reports:read', 'old');

        // Act
        $changed = $scopes->updateDescription('reports:read', 'Read reports');
        $builtIn = $scopes->updateDescription('openid', 'changed');

        // Assert
        $this->assertTrue($changed);
        $this->assertFalse($builtIn);
        $this->assertSame(['Signs the person in. Required for OpenID Connect.', 'Read reports'], array_column($scopes->all(), 'description'));
    }
}
