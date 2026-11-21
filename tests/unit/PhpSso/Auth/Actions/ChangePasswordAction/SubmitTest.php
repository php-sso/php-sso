<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Actions\ChangePasswordAction;

use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteSessions;

#[CoversMethod(ChangePasswordAction::class, 'submit')]
final class SubmitTest extends TestCase
{
    private TestData $data;
    private Sessions $sessions;
    private ChangePasswordAction $action;
    private string $sub;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->sessions = new SqliteSessions($this->data->db, 3600);
        $this->action = new ChangePasswordAction($this->data->users, $this->sessions);
        $this->sub = $this->data->user('alice');
        $this->data->users->setMustChangePassword($this->sub, true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAGoodPasswordIsSavedAndEndsTheRequirement(): void
    {
        // Arrange
        $user = $this->data->users->find($this->sub);

        // Act
        $error = $this->action->submit($user, ['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one'], '203.0.113.9');

        // Assert
        $saved = $this->data->users->find($this->sub);
        $this->assertNull($error);
        $this->assertTrue(password_verify('a-brand-new-one', $saved['password_hash']));
        $this->assertSame(0, (int) $saved['must_change_password']);
    }

    public function testATooShortPasswordIsRefusedAndNothingChanges(): void
    {
        // Arrange
        $user = $this->data->users->find($this->sub);

        // Act
        $error = $this->action->submit($user, ['new_password' => 'short', 'confirm_password' => 'short'], '203.0.113.9');

        // Assert
        $saved = $this->data->users->find($this->sub);
        $this->assertSame('Use at least 8 characters.', $error);
        $this->assertSame(1, (int) $saved['must_change_password']);
        $this->assertSame($user['password_hash'], $saved['password_hash']);
    }

    public function testTwoDifferentEntriesAreRefused(): void
    {
        // Arrange
        $user = $this->data->users->find($this->sub);

        // Act
        $error = $this->action->submit($user, ['new_password' => 'a-brand-new-one', 'confirm_password' => 'another-one-here'], '203.0.113.9');

        // Assert
        $this->assertSame('The two passwords do not match.', $error);
        $this->assertSame(1, (int) $this->data->users->find($this->sub)['must_change_password']);
    }

    public function testKeepingTheSamePasswordIsRefused(): void
    {
        // Arrange
        $user = $this->data->users->find($this->sub);

        // Act
        $error = $this->action->submit($user, ['new_password' => 'secret-pass', 'confirm_password' => 'secret-pass'], '203.0.113.9');

        // Assert
        $this->assertStringContainsString('different', (string) $error);
        $this->assertSame(1, (int) $this->data->users->find($this->sub)['must_change_password']);
    }

    public function testOtherSessionsOfThePersonEndButTheCurrentOneStays(): void
    {
        // Arrange
        $other = $this->sessions->start($this->sub, '203.0.113.1', 'phone');
        $current = $this->sessions->start($this->sub, '203.0.113.2', 'laptop');
        $_SESSION['sid'] = $current;
        $user = $this->data->users->find($this->sub);

        // Act
        $this->action->submit($user, ['new_password' => 'a-brand-new-one', 'confirm_password' => 'a-brand-new-one'], '203.0.113.9');

        // Assert
        $this->assertFalse($this->sessions->touch($other, $this->sub));
        $this->assertTrue($this->sessions->touch($current, $this->sub));
    }
}
