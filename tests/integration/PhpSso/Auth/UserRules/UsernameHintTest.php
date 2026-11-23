<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\UserRules;

use PhpSso\Auth\UserRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(UserRules::class, 'usernameHint')]
final class UsernameHintTest extends TestCase
{
    public function testItDoesNotOfferTheAtSignWhenItIsRefused(): void
    {
        // Arrange
        $rules = new UserRules((new TestData())->users, 'username_or_email');

        // Act
        $hint = $rules->usernameHint();

        // Assert
        $this->assertStringContainsString('no @', $hint);
    }

    public function testItOffersTheAtSignOtherwise(): void
    {
        // Arrange
        $rules = new UserRules((new TestData())->users, 'username');

        // Act
        $hint = $rules->usernameHint();

        // Assert
        $this->assertStringContainsString('. _ @ -', $hint);
    }
}
