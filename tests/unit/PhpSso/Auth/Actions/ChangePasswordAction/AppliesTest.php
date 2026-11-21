<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Actions\ChangePasswordAction;

use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(ChangePasswordAction::class, 'applies')]
final class AppliesTest extends TestCase
{
    public function testItAppliesToSomeoneFlaggedWhoHasAPassword(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->users->setMustChangePassword($sub, true);
        $action = new ChangePasswordAction($data->users, new Sessions($data->db, 3600));

        // Act
        $applies = $action->applies($data->users->find($sub));

        // Assert
        $this->assertTrue($applies);
    }

    public function testItDoesNotApplyToSomeoneNotFlagged(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $action = new ChangePasswordAction($data->users, new Sessions($data->db, 3600));

        // Act
        $applies = $action->applies($data->users->find($sub));

        // Assert
        $this->assertFalse($applies);
    }

    public function testItDoesNotApplyToSomeoneWithoutALocalPassword(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->users->create(['username' => 'bob', 'password' => null, 'name' => 'Bob', 'email' => 'bob@example.com', 'email_verified' => true, 'is_admin' => false, 'must_change_password' => true]);
        $action = new ChangePasswordAction($data->users, new Sessions($data->db, 3600));

        // Act
        $applies = $action->applies($data->users->find($sub));

        // Assert
        $this->assertFalse($applies);
    }
}
