<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\UserRules;

use PhpSso\Auth\UserRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(UserRules::class, 'usernameError')]
final class UsernameErrorTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> identifier type, username, whether it is refused */
    public static function cases(): array
    {
        return [
            'at sign refused for username or email' => ['username_or_email', 'bob@corp', true],
            'plain name fine for username or email' => ['username_or_email', 'bob', false],
            'at sign fine for username only' => ['username', 'bob@corp', false],
            'at sign fine for email only' => ['email', 'bob@corp', false],
        ];
    }

    #[DataProvider('cases')]
    public function testItRefusesAnAtSignOnlyWhereItWouldBeAmbiguous(string $type, string $username, bool $refused): void
    {
        // Arrange
        $rules = new UserRules((new TestData())->users, $type);

        // Act
        $error = $rules->usernameError($username);

        // Assert
        $this->assertSame($refused, $error !== null);
    }
}
