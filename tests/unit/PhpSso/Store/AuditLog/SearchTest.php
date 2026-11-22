<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\AuditLog;

use PhpSso\Store\AuditLog;
use PhpSso\Store\Sql\SqlAuditLog;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

#[CoversMethod(AuditLog::class, 'search')]
final class SearchTest extends TestCase
{
    private function filled(): SqlAuditLog
    {
        $log = new SqlAuditLog(TestConnection::open());
        $log->record(['actor_sub' => 'sub-a', 'actor' => 'alice', 'action' => 'user.create', 'target_type' => 'user', 'target' => 'sub-x', 'target_label' => 'bob'], 1000);
        $log->record(['actor_sub' => 'sub-a', 'actor' => 'alice', 'action' => 'client.delete', 'target_type' => 'client', 'target' => 'app-1', 'target_label' => 'Billing app'], 2000);
        $log->record(['actor_sub' => 'sub-c', 'actor' => 'carol', 'action' => 'user.lock', 'target_type' => 'user', 'target' => 'sub-x', 'target_label' => 'bob'], 3000);
        $log->record(['actor' => 'cli:ops', 'action' => 'keys.rotate'], 4000);

        return $log;
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function actions(array $rows): array
    {
        return array_column($rows, 'action');
    }

    public function testItListsTheNewestFirst(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $rows = $log->search([], 10);

        // Assert
        $this->assertSame(['keys.rotate', 'user.lock', 'client.delete', 'user.create'], $this->actions($rows));
    }

    public function testItFiltersByTheStartOfTheAction(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $rows = $log->search(['action' => 'user.'], 10);

        // Assert
        $this->assertSame(['user.lock', 'user.create'], $this->actions($rows));
    }

    public function testAWildcardInAFilterIsLiteral(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $rows = $log->search(['action' => '%'], 10);

        // Assert
        $this->assertSame([], $rows);
    }

    public function testItFiltersByAnActorsNameIgnoringCaseOrByTheirId(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $byName = $log->search(['actor' => 'ALI'], 10);
        $byId = $log->search(['actor' => 'sub-c'], 10);

        // Assert
        $this->assertSame(['client.delete', 'user.create'], $this->actions($byName));
        $this->assertSame(['user.lock'], $this->actions($byId));
    }

    public function testItFiltersByATargetsIdOrName(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $byId = $log->search(['target' => 'app-1'], 10);
        $byName = $log->search(['target' => 'bob'], 10);

        // Assert
        $this->assertSame(['client.delete'], $this->actions($byId));
        $this->assertSame(['user.lock', 'user.create'], $this->actions($byName));
    }

    public function testItFiltersByTimeInclusively(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $rows = $log->search(['since' => 2000, 'until' => 3000], 10);

        // Assert
        $this->assertSame(['user.lock', 'client.delete'], $this->actions($rows));
    }

    public function testFiltersCombine(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $rows = $log->search(['action' => 'user.', 'actor' => 'alice', 'since' => 500], 10);

        // Assert
        $this->assertSame(['user.create'], $this->actions($rows));
    }

    public function testItPagesWithALimitAndAnOffset(): void
    {
        // Arrange
        $log = $this->filled();

        // Act
        $page = $log->search([], 2, 1);

        // Assert
        $this->assertSame(['user.lock', 'client.delete'], $this->actions($page));
    }
}
