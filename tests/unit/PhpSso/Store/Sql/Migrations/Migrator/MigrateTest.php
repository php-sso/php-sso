<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Migrations\Migrator;

use PhpSso\Store\SchemaTooNew;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migrator;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\unit\PhpSso\Support\FakeMigration;
use Tests\unit\PhpSso\Support\UsesMigrationSandbox;

#[CoversMethod(Migrator::class, 'migrate')]
final class MigrateTest extends TestCase
{
    use UsesMigrationSandbox;

    protected function setUp(): void
    {
        $this->setUpSandbox();
    }

    protected function tearDown(): void
    {
        $this->tearDownSandbox();
    }

    public function testItRunsEveryPendingMigrationInOrder(): void
    {
        // Arrange
        $order = [];
        $migrator = $this->migrator([
            new FakeMigration('0001', static function () use (&$order): void { $order[] = '0001'; }),
            new FakeMigration('0002', static function () use (&$order): void { $order[] = '0002'; }),
        ]);

        // Act
        $ran = $migrator->migrate();

        // Assert
        $this->assertSame(['0001', '0002'], $order);
        $this->assertSame(['0001', '0002'], array_map(static fn ($m): string => $m->id(), $ran));
    }

    public function testItRecordsTheVersionsThatRan(): void
    {
        // Arrange
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $migrator->migrate();

        // Assert
        $this->assertSame(['0001', '0002'], $this->recordedVersions());
        $this->assertTrue($this->db->tableExists('mig_probe_a'));
        $this->assertTrue($this->db->tableExists('mig_probe_b'));
    }

    public function testItRecordsWhenAMigrationRan(): void
    {
        // Arrange
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a')]);
        $before = time();

        // Act
        $migrator->migrate();

        // Assert
        $appliedAt = $migrator->applied()['0001'];
        $this->assertGreaterThanOrEqual($before, $appliedAt);
        $this->assertLessThanOrEqual(time(), $appliedAt);
    }

    public function testASecondRunDoesNothing(): void
    {
        // Arrange
        $runs = 0;
        $migrator = $this->migrator([new FakeMigration('0001', static function () use (&$runs): void { $runs++; })]);
        $migrator->migrate();

        // Act
        $ran = $migrator->migrate();

        // Assert
        $this->assertSame([], $ran);
        $this->assertSame(1, $runs);
    }

    public function testItRunsOnlyTheMigrationsThatAreNewSinceTheLastRun(): void
    {
        // Arrange
        $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a')])->migrate();
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $ran = $migrator->migrate();

        // Assert
        $this->assertSame(['0002'], array_map(static fn ($m): string => $m->id(), $ran));
        $this->assertSame(['0001', '0002'], $this->recordedVersions());
    }

    public function testItTellsWhichMigrationIsStarting(): void
    {
        // Arrange
        $started = [];
        $migrator = $this->migrator([FakeMigration::createsTable('0001', 'mig_probe_a'), FakeMigration::createsTable('0002', 'mig_probe_b')]);

        // Act
        $migrator->migrate(static function ($migration) use (&$started): void { $started[] = $migration->id(); });

        // Assert
        $this->assertSame(['0001', '0002'], $started);
    }

    public function testAFailedMigrationRethrowsAndRecordsNothing(): void
    {
        // Arrange
        $migrator = $this->migrator([new FakeMigration('0001', static function (Connection $db): void {
            $db->pdo->exec('CREATE TABLE mig_probe_a (id INTEGER NOT NULL PRIMARY KEY)');
            throw new RuntimeException('step two broke');
        })]);

        // Act
        $failure = null;
        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertSame('step two broke', $failure?->getMessage());
        $this->assertSame([], $this->recordedVersions());
        $this->assertSame(['0001'], array_map(static fn ($m): string => $m->id(), $migrator->pending()));
    }

    public function testAFailedMigrationLeavesNothingBehindWhereDdlCanBeRolledBack(): void
    {
        // Arrange
        if (!$this->db->dialect->transactionalDdl()) {
            $this->markTestSkipped('MySQL cannot roll back DDL: a failed migration is retried instead (see the next test).');
        }
        $migrator = $this->migrator([new FakeMigration('0001', static function (Connection $db): void {
            $db->pdo->exec('CREATE TABLE mig_probe_a (id INTEGER NOT NULL PRIMARY KEY)');
            throw new RuntimeException('step two broke');
        })]);

        // Act
        try {
            $migrator->migrate();
        } catch (RuntimeException) {
        }

        // Assert
        $this->assertFalse($this->db->tableExists('mig_probe_a'));
    }

    public function testAFailedMigrationIsRetriedAndThenRecorded(): void
    {
        // Arrange
        $attempt = 0;
        $migrator = $this->migrator([new FakeMigration('0001', static function (Connection $db) use (&$attempt): void {
            if (!$db->tableExists('mig_probe_a')) { // guarded, so that running it again after a failure is safe (MySQL keeps the table)
                $db->pdo->exec('CREATE TABLE mig_probe_a (id INTEGER NOT NULL PRIMARY KEY)');
            }
            if (++$attempt === 1) {
                throw new RuntimeException('first attempt broke');
            }
        })]);
        try {
            $migrator->migrate();
        } catch (RuntimeException) {
        }

        // Act
        $ran = $migrator->migrate();

        // Assert
        $this->assertSame(['0001'], array_map(static fn ($m): string => $m->id(), $ran));
        $this->assertSame(['0001'], $this->recordedVersions());
        $this->assertTrue($this->db->tableExists('mig_probe_a'));
    }

    public function testAFailureKeepsTheMigrationsThatRanBeforeIt(): void
    {
        // Arrange
        $migrator = $this->migrator([
            FakeMigration::createsTable('0001', 'mig_probe_a'),
            new FakeMigration('0002', static function (): void { throw new RuntimeException('broke'); }),
        ]);

        // Act
        try {
            $migrator->migrate();
        } catch (RuntimeException) {
        }

        // Assert
        $this->assertSame(['0001'], $this->recordedVersions());
    }

    public function testASqliteMigrationSwitchesForeignKeysOffWhileItRunsAndOnAgain(): void
    {
        // Arrange
        if ($this->db->dialect->name() !== 'sqlite') {
            $this->markTestSkipped('Foreign keys are a SQLite pragma.');
        }
        $during = null;
        $migrator = $this->migrator([new FakeMigration('0001', static function (Connection $db) use (&$during): void {
            $during = (int) $db->pdo->query('PRAGMA foreign_keys')->fetchColumn();
        })]);

        // Act
        $migrator->migrate();

        // Assert
        $this->assertSame(0, $during);
        $this->assertSame(1, (int) $this->db->pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testASqliteMigrationThatBreaksAForeignKeyIsRolledBackAndForeignKeysComeBackOn(): void
    {
        // Arrange
        if ($this->db->dialect->name() !== 'sqlite') {
            $this->markTestSkipped('Foreign keys are a SQLite pragma.');
        }
        $migrator = $this->migrator([new FakeMigration('0001', static function (Connection $db): void {
            $db->pdo->exec('CREATE TABLE mig_probe_parent (id INTEGER PRIMARY KEY)');
            $db->pdo->exec('CREATE TABLE mig_probe_child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES mig_probe_parent (id))');
            $db->pdo->exec('INSERT INTO mig_probe_child (id, parent_id) VALUES (1, 99)');
        })]);

        // Act
        $failure = null;
        try {
            $migrator->migrate();
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertNotNull($failure);
        $this->assertSame([], $this->recordedVersions());
        $this->assertFalse($this->db->tableExists('mig_probe_child'));
        $this->assertSame(1, (int) $this->db->pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function testItRefusesADatabaseANewerVersionUpgradedAndRunsNothing(): void
    {
        // Arrange
        $this->migrator([FakeMigration::noop('0001'), FakeMigration::noop('0002')])->migrate();
        $ran = [];
        $older = $this->migrator([FakeMigration::noop('0001'), new FakeMigration('0003', static function () use (&$ran): void { $ran[] = '0003'; })]);
        $this->expectException(SchemaTooNew::class);
        $this->expectExceptionMessage('0002');

        // Act
        try {
            $older->migrate();
        } finally {
            // Assert (also: its own pending migration did not run over the newer schema)
            $this->assertSame([], $ran);
            $this->assertSame(['0001', '0002'], $this->recordedVersions());
        }
    }
}
