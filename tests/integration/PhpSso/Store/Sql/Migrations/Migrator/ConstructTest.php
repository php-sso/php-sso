<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Sql\Migrations\Migrator;

use LogicException;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migrations;
use PhpSso\Store\Sql\Migrations\Migrator;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\FakeMigration;

#[CoversMethod(Migrator::class, '__construct')]
final class ConstructTest extends TestCase
{
    public function testTwoMigrationsMayNotShareAnId(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');
        $this->expectException(LogicException::class);

        // Act
        new Migrator($db, [FakeMigration::createsTable('0001', 'a'), FakeMigration::createsTable('0001', 'b')]);

        // Assert (the exception)
    }

    public function testMigrationsMustComeInOrder(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');
        $this->expectException(LogicException::class);

        // Act
        new Migrator($db, [FakeMigration::createsTable('0002', 'a'), FakeMigration::createsTable('0001', 'b')]);

        // Assert (the exception)
    }

    public function testAnIdIsANumber(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');
        $this->expectException(LogicException::class);

        // Act
        new Migrator($db, [FakeMigration::createsTable('first', 'a')]);

        // Assert (the exception)
    }

    public function testTheAppsOwnListIsValid(): void
    {
        // Arrange
        $db = Connection::sqlite(':memory:');

        // Act
        $migrator = new Migrator($db, Migrations::all());

        // Assert
        $this->assertSame('0001', $migrator->status()[0]['migration']->id());
    }
}
