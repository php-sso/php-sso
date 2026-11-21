<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Scopes;

use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Scopes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PhpSso\Store\ScopeName;
use PhpSso\Store\Sql\SqlScopes;

#[CoversMethod(Scopes::class, 'exists')]
#[CoversMethod(Scopes::class, 'isBuiltin')]
final class ExistsTest extends TestCase
{
    public function testNamesAreComparedWithoutCase(): void
    {
        // Arrange
        $scopes = new SqlScopes(Connection::sqlite(':memory:'), ['openid', 'email']);
        $scopes->create('reports:read', '');

        // Act
        $results = [$scopes->exists('REPORTS:Read'), $scopes->exists('Email'), $scopes->exists('other')];

        // Assert
        $this->assertSame([true, true, false], $results);
    }

    public function testOnlyTheConfiguredScopesAreBuiltIn(): void
    {
        // Arrange
        $scopes = new SqlScopes(Connection::sqlite(':memory:'), ['openid']);
        $scopes->create('reports:read', '');

        // Act
        $results = [$scopes->isBuiltin('openid'), $scopes->isBuiltin('reports:read'), $scopes->isBuiltin('OpenID')];

        // Assert
        $this->assertSame([true, false, false], $results);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'letters, digits and . _ : -' => ['invoices.read_v2:all-items', true];
        yield 'one letter' => ['a', true];
        yield 'a whole word' => ['reports', true];
        yield 'sixty-four characters' => [str_repeat('a', 64), true];
        yield 'sixty-five characters' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
        yield 'a space' => ['read invoices', false];
        yield 'a slash' => ['invoices/read', false];
        yield 'a quote' => ['read"', false];
        yield 'a backslash' => ['read\\', false];
        yield 'starting with a dot' => ['.hidden', false];
        yield 'non-ASCII' => ["caf\u{e9}", false];
        yield 'a newline' => ["read\nwrite", false];
    }

    #[DataProvider('names')]
    public function testOnlySafeNamesAreValid(string $name, bool $valid): void
    {
        // Arrange
        // (the name and whether it is acceptable come from the data set)

        // Act
        $result = ScopeName::isValid($name);

        // Assert
        $this->assertSame($valid, $result);
    }
}
