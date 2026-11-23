<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Impersonation;

use PhpSso\Audit\Audit;
use PhpSso\Auth\Impersonation;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\InMemoryAuditLog;

#[CoversMethod(Impersonation::class, 'stop')]
final class StopAuditTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testStoppingIsRecordedAsTheAdministratorAboutThePersonTheyWereBrowsingAs(): void
    {
        // Arrange
        $app = new Support();
        $log = new InMemoryAuditLog();
        $impersonation = new Impersonation($app->sessions, $app->data->users, new Audit($log));
        $admin = $app->admin();
        $target = $app->data->users->find($app->data->user('alice'));
        $impersonation->start($admin, $target['sub'], '203.0.113.7', 'test');

        // Act
        $impersonation->stop('203.0.113.7');

        // Assert
        $this->assertCount(1, $log->entries);
        $this->assertSame('impersonation.stop', $log->entries[0]['action']);
        $this->assertSame($admin['sub'], $log->entries[0]['actor_sub']);
        $this->assertSame($target['sub'], $log->entries[0]['target']);
        $this->assertSame('alice', $log->entries[0]['target_label']);
        $this->assertSame('203.0.113.7', $log->entries[0]['ip']);
    }

    public function testStoppingWhenNobodyIsBeingImpersonatedRecordsNothing(): void
    {
        // Arrange
        $app = new Support();
        $log = new InMemoryAuditLog();
        $impersonation = new Impersonation($app->sessions, $app->data->users, new Audit($log));

        // Act
        $impersonation->stop('203.0.113.7');

        // Assert
        $this->assertSame([], $log->entries);
    }
}
