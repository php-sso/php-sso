<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Impersonation;

use PhpSso\Auth\Impersonation;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Impersonation::class, 'start')]
#[CoversMethod(Impersonation::class, 'refusal')]
#[CoversMethod(Impersonation::class, 'active')]
final class StartTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItSignsTheBrowserInAsThePerson(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');

        // Act
        $error = $app->impersonation->start($app->admin(), $alice, '203.0.113.1', 'agent');

        // Assert
        $this->assertNull($error);
        $this->assertSame($alice, $_SESSION['user']);
        $this->assertNotSame($app->adminSid, $_SESSION['sid']);
        $this->assertTrue($app->sessions->touch($_SESSION['sid'], $alice));
    }

    public function testItKeepsTheAdministratorsOwnSessionAside(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');

        // Act
        $app->impersonation->start($app->admin(), $alice, '203.0.113.1', 'agent');

        // Assert
        $this->assertSame(['sub' => $app->adminSub, 'sid' => $app->adminSid], $app->impersonation->active());
        $this->assertTrue($app->sessions->touch($app->adminSid, $app->adminSub));
    }

    public function testItMarksThePersonsSessionWithTheAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');

        // Act
        $app->impersonation->start($app->admin(), $alice, '203.0.113.1', 'agent');

        // Assert
        $row = array_values(array_filter($app->sessions->active(), static fn (array $s): bool => $s['sub'] === $alice))[0];
        $this->assertSame('Root', $row['impersonated_by_name']);
    }

    public function testItRefusesAnAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $other = $app->data->users->create(['username' => 'boss', 'password' => 'secret-pass', 'name' => 'Boss', 'email' => 'boss@example.com', 'email_verified' => true, 'is_admin' => true]);

        // Act
        $error = $app->impersonation->start($app->admin(), $other, '203.0.113.1', 'agent');

        // Assert
        $this->assertSame('Administrators cannot be impersonated.', $error);
        $this->assertSame($app->adminSub, $_SESSION['user']);
        $this->assertNull($app->impersonation->active());
    }

    public function testItRefusesALockedAccount(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');
        $app->data->users->setLocked($alice, true);

        // Act
        $error = $app->impersonation->start($app->admin(), $alice, '203.0.113.1', 'agent');

        // Assert
        $this->assertStringContainsString('is locked', (string) $error);
        $this->assertSame($app->adminSub, $_SESSION['user']);
    }

    public function testItRefusesTheAdministratorThemselves(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $error = $app->impersonation->start($app->admin(), $app->adminSub, '203.0.113.1', 'agent');

        // Assert
        $this->assertSame('That is you.', $error);
    }

    public function testItRefusesAPersonWhoNoLongerExists(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $error = $app->impersonation->start($app->admin(), 'gone', '203.0.113.1', 'agent');

        // Assert
        $this->assertSame('That person no longer exists.', $error);
    }

    public function testItRefusesToStartWhileAnotherImpersonationRuns(): void
    {
        // Arrange
        $app = new Support();
        $app->impersonation->start($app->admin(), $app->data->user('alice'), '203.0.113.1', 'agent');

        // Act
        $error = $app->impersonation->start($app->admin(), $app->data->user('bob'), '203.0.113.1', 'agent');

        // Assert
        $this->assertSame('Stop the current impersonation first.', $error);
    }
}
