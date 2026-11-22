<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\Dialect;

use PhpSso\Store\Sql\Connection;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestConnection;

/** What a migration asks before it changes something, so that running it again is safe. */
#[CoversMethod(Connection::class, 'tableExists')]
#[CoversMethod(Connection::class, 'columnExists')]
#[CoversMethod(Connection::class, 'indexExists')]
final class GuardsTest extends TestCase
{
    public function testATableThatExistsIsFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->tableExists('users');

        // Assert
        $this->assertTrue($exists);
    }

    public function testATableThatDoesNotExistIsNotFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->tableExists('no_such_table');

        // Assert
        $this->assertFalse($exists);
    }

    public function testAColumnThatExistsIsFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->columnExists('users', 'username');

        // Assert
        $this->assertTrue($exists);
    }

    public function testAColumnThatDoesNotExistIsNotFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->columnExists('users', 'no_such_column');

        // Assert
        $this->assertFalse($exists);
    }

    public function testAColumnOfAnotherTableIsNotFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->columnExists('clients', 'username');

        // Assert
        $this->assertFalse($exists);
    }

    public function testAnIndexThatExistsIsFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->indexExists('client_auth_failures', 'client_auth_failures_ip');

        // Assert
        $this->assertTrue($exists);
    }

    public function testAnIndexThatDoesNotExistIsNotFound(): void
    {
        // Arrange
        $db = TestConnection::open();

        // Act
        $exists = $db->indexExists('client_auth_failures', 'no_such_index');

        // Assert
        $this->assertFalse($exists);
    }
}
