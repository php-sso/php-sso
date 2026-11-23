<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Impersonation;

use PhpSso\Auth\Impersonation;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Impersonation::class, 'stop')]
#[CoversMethod(Impersonation::class, 'keepAlive')]
final class StopTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItGivesTheBrowserBackToTheAdministrator(): void
    {
        // Arrange
        $app = new Support();
        $app->impersonation->start($app->admin(), $app->data->user('alice'), '203.0.113.1', 'agent');

        // Act
        $restored = $app->impersonation->stop();

        // Assert
        $this->assertTrue($restored);
        $this->assertSame($app->adminSub, $_SESSION['user']);
        $this->assertSame($app->adminSid, $_SESSION['sid']);
        $this->assertNull($app->impersonation->active());
    }

    public function testItEndsThePersonsSession(): void
    {
        // Arrange
        $app = new Support();
        $alice = $app->data->user('alice');
        $app->impersonation->start($app->admin(), $alice, '203.0.113.1', 'agent');
        $impersonated = $_SESSION['sid'];

        // Act
        $app->impersonation->stop();

        // Assert
        $this->assertFalse($app->sessions->touch($impersonated, $alice));
    }

    public function testItSignsTheBrowserOutWhenTheAdministratorsSessionEndedMeanwhile(): void
    {
        // Arrange
        $app = new Support();
        $app->impersonation->start($app->admin(), $app->data->user('alice'), '203.0.113.1', 'agent');
        $app->sessions->end($app->adminSid);

        // Act
        $restored = $app->impersonation->stop();

        // Assert
        $this->assertFalse($restored);
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertArrayNotHasKey('sid', $_SESSION);
    }

    public function testItDoesNothingWhenNoOneIsImpersonating(): void
    {
        // Arrange
        $app = new Support();

        // Act
        $restored = $app->impersonation->stop();

        // Assert
        $this->assertFalse($restored);
        $this->assertSame($app->adminSub, $_SESSION['user']);
    }
}
