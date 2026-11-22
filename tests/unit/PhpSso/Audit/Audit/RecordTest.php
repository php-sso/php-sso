<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\Audit;

use PhpSso\Audit\Audit;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\InMemoryAuditLog;

#[CoversMethod(Audit::class, 'record')]
final class RecordTest extends TestCase
{
    private string $errors;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->errors = sys_get_temp_dir() . '/audit-errors-' . bin2hex(random_bytes(4)) . '.log';
        $this->previousErrorLog = ini_set('error_log', $this->errors);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errors);
    }

    private function logged(): string
    {
        return is_file($this->errors) ? (string) file_get_contents($this->errors) : '';
    }

    public function testAPersonIsRecordedByIdAndName(): void
    {
        // Arrange
        $log = new InMemoryAuditLog();

        // Act
        (new Audit($log))->record('user.lock', ['sub' => 'sub-1', 'username' => 'admin', 'email' => 'x'], '203.0.113.9', 'user', 'sub-2', 'alice', ['why' => 'test']);

        // Assert
        $this->assertSame([[
            'actor_sub' => 'sub-1', 'actor' => 'admin', 'ip' => '203.0.113.9', 'action' => 'user.lock',
            'target_type' => 'user', 'target' => 'sub-2', 'target_label' => 'alice', 'detail' => ['why' => 'test'],
        ]], $log->entries);
    }

    public function testSomethingThatIsNotAPersonIsRecordedByName(): void
    {
        // Arrange
        $log = new InMemoryAuditLog();

        // Act
        (new Audit($log))->record('keys.rotate', 'cli:deploy');

        // Assert
        $this->assertNull($log->entries[0]['actor_sub']);
        $this->assertSame('cli:deploy', $log->entries[0]['actor']);
    }

    public function testALogThatCannotBeWrittenDoesNotStopTheActionButTheEntryIsNotLost(): void
    {
        // Arrange
        $audit = new Audit(new InMemoryAuditLog(failing: true));

        // Act
        $audit->record('user.delete', ['sub' => 's', 'username' => 'admin'], '', 'user', 'sub-2', 'alice');

        // Assert
        $this->assertStringContainsString('could not write the audit log', $this->logged());
        $this->assertStringContainsString('user.delete', $this->logged());
        $this->assertStringContainsString('alice', $this->logged());
    }

    public function testWithAStreamEveryEntryIsAlsoWrittenAsAJsonLine(): void
    {
        // Arrange
        $audit = new Audit(new InMemoryAuditLog(), stream: true);

        // Act
        $audit->record('client.delete', ['sub' => 's', 'username' => 'admin'], '198.51.100.1', 'client', 'app-1', 'Billing');

        // Assert
        $this->assertMatchesRegularExpression('/SSO_AUDIT \{"at":\d+,"actor_sub":"s","actor":"admin","ip":"198\.51\.100\.1","action":"client\.delete"/', $this->logged());
    }

    public function testWithoutAStreamNothingIsWrittenToTheErrorLog(): void
    {
        // Arrange
        $audit = new Audit(new InMemoryAuditLog());

        // Act
        $audit->record('client.delete', 'cli:x');

        // Assert
        $this->assertSame('', $this->logged());
    }
}
