<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Identifier\IdentifierResolverFactory;

use PhpSso\Auth\Identifier\EmailResolver;
use PhpSso\Auth\Identifier\IdentifierResolverFactory;
use PhpSso\Auth\Identifier\UsernameOrEmailResolver;
use PhpSso\Auth\Identifier\UsernameResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(IdentifierResolverFactory::class, 'create')]
final class CreateTest extends TestCase
{
    /** @return array<string, array{string, class-string}> */
    public static function types(): array
    {
        return [
            'username' => ['username', UsernameResolver::class],
            'email' => ['email', EmailResolver::class],
            'username or email' => ['username_or_email', UsernameOrEmailResolver::class],
        ];
    }

    #[DataProvider('types')]
    public function testItBuildsTheResolverForTheType(string $type, string $class): void
    {
        // Arrange
        $users = (new TestData())->users;

        // Act
        $resolver = IdentifierResolverFactory::create($type, $users);

        // Assert
        $this->assertInstanceOf($class, $resolver);
    }

    public function testItRejectsAnUnknownTypeAndListsTheValidOnes(): void
    {
        // Arrange
        $users = (new TestData())->users;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('username, email, username_or_email');

        // Act
        IdentifierResolverFactory::create('phone', $users);

        // Assert (the exception above)
    }
}
