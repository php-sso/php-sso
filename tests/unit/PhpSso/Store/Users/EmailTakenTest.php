<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'emailTaken')]
#[CoversMethod(Users::class, 'create')]
final class EmailTakenTest extends TestCase
{
    public function testItIsTakenByAnotherPersonIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');

        // Act
        $taken = $data->users->emailTaken('Alice@EXAMPLE.com');

        // Assert
        $this->assertTrue($taken);
    }

    public function testItIsNotTakenByThePersonAsked(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');

        // Act
        $taken = $data->users->emailTaken('alice@example.com', $sub);

        // Assert
        $this->assertFalse($taken);
    }

    public function testAPersonCreatedWithoutAPasswordHasNoPasswordHash(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $sub = $data->users->create(['username' => 'scim', 'password' => null, 'name' => 'Scim', 'email' => 'scim@example.com', 'email_verified' => true, 'is_admin' => false]);

        // Assert
        $this->assertNull($data->users->find($sub)['password_hash']);
    }
}
