<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Users;

use PhpSso\Store\DuplicateValue;
use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'create')]
final class CreateTest extends TestCase
{
    public function testAUserNameDifferingOnlyInCaseIsTaken(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $this->expectException(DuplicateValue::class);

        // Act
        $data->users->create(['username' => 'ALICE', 'password' => 'secret-pass', 'name' => 'Other', 'email' => 'other@example.com', 'email_verified' => false, 'is_admin' => false]);

        // Assert (the exception above)
    }
}
