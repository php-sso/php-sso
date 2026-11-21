<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\UserRules;

use PhpSso\Auth\UserRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(UserRules::class, 'emailError')]
final class EmailErrorTest extends TestCase
{
    public function testItRefusesAnAddressAnotherPersonHasWhenSigningInByEmail(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $rules = new UserRules($data->users, 'email');

        // Act
        $error = $rules->emailError('ALICE@example.com', null);

        // Assert
        $this->assertNotNull($error);
    }

    public function testItAllowsAPersonToKeepTheirOwnAddress(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $rules = new UserRules($data->users, 'email');

        // Act
        $error = $rules->emailError('alice@example.com', $sub);

        // Assert
        $this->assertNull($error);
    }

    public function testItAllowsSharedAddressesWhenSigningInByUsername(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $rules = new UserRules($data->users, 'username');

        // Act
        $error = $rules->emailError('alice@example.com', null);

        // Assert
        $this->assertNull($error);
    }
}
