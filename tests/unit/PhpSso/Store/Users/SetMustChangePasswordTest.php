<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'setMustChangePassword')]
#[CoversMethod(Users::class, 'setPassword')]
#[CoversMethod(Users::class, 'update')]
#[CoversMethod(Users::class, 'create')]
final class SetMustChangePasswordTest extends TestCase
{
    public function testTheFlagCanBeSetAndCleared(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');

        // Act
        $data->users->setMustChangePassword($sub, true);
        $set = (int) $data->users->find($sub)['must_change_password'];
        $data->users->setMustChangePassword($sub, false);

        // Assert
        $this->assertSame(1, $set);
        $this->assertSame(0, (int) $data->users->find($sub)['must_change_password']);
    }

    public function testChoosingAPasswordClearsTheFlag(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->users->setMustChangePassword($sub, true);

        // Act
        $data->users->setPassword($sub, 'a-brand-new-one');

        // Assert
        $this->assertSame(0, (int) $data->users->find($sub)['must_change_password']);
    }

    public function testAnAdministratorSettingAPasswordKeepsTheFlagTheyChose(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $user = $data->users->find($sub);

        // Act
        $data->users->update($sub, ['username' => 'alice', 'name' => 'Alice', 'email' => 'alice@example.com', 'email_verified' => true, 'is_admin' => false, 'password' => 'chosen-by-admin', 'must_change_password' => true]);

        // Assert
        $saved = $data->users->find($sub);
        $this->assertSame(1, (int) $saved['must_change_password']);
        $this->assertTrue(password_verify('chosen-by-admin', $saved['password_hash']));
        $this->assertNotSame($user['password_hash'], $saved['password_hash']);
    }

    public function testAnUpdateWithoutTheFieldLeavesTheFlagAlone(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->users->setMustChangePassword($sub, true);

        // Act
        $data->users->update($sub, ['username' => 'alice', 'name' => 'Alice B', 'email' => 'alice@example.com', 'email_verified' => true, 'is_admin' => false, 'password' => '']);

        // Assert
        $this->assertSame(1, (int) $data->users->find($sub)['must_change_password']);
    }

    public function testANewUserCanBeCreatedFlagged(): void
    {
        // Arrange
        $data = new TestData();

        // Act
        $sub = $data->users->create(['username' => 'bob', 'password' => 'first-pass-1', 'name' => 'Bob', 'email' => 'bob@example.com', 'email_verified' => false, 'is_admin' => false, 'must_change_password' => true]);

        // Assert
        $this->assertSame(1, (int) $data->users->find($sub)['must_change_password']);
    }
}
