<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Identities;

use PhpSso\Store\Identities;
use PDOException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteIdentities;

#[CoversMethod(Identities::class, 'link')]
#[CoversMethod(Identities::class, 'find')]
#[CoversMethod(Identities::class, 'forUser')]
#[CoversMethod(Identities::class, 'touch')]
#[CoversMethod(Identities::class, 'unlink')]
final class LinkTest extends TestCase
{
    public function testItFindsAProviderAccountByProviderAndSubject(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $identities = new SqliteIdentities($data->db);
        $identities->link($alice, 'google', 'g-1', 'alice@example.com');

        // Act
        $found = $identities->find('google', 'g-1');

        // Assert
        $this->assertSame($alice, $found['user_sub']);
        $this->assertNull($identities->find('google', 'g-2'));
        $this->assertNull($identities->find('github', 'g-1'));
    }

    public function testAProviderAccountCanBelongToOnlyOnePerson(): void
    {
        // Arrange
        $data = new TestData();
        $identities = new SqliteIdentities($data->db);
        $identities->link($data->user('alice'), 'google', 'g-1', null);
        $this->expectException(PDOException::class);

        // Act
        $identities->link($data->user('bob'), 'google', 'g-1', null);

        // Assert (the exception above)
    }

    public function testItListsAPersonsAccountsAndNotsAnotherPersons(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $identities = new SqliteIdentities($data->db);
        $identities->link($alice, 'google', 'g-1', null);
        $identities->link($alice, 'github', 'h-1', null);
        $identities->link($data->user('bob'), 'google', 'g-2', null);

        // Act
        $list = $identities->forUser($alice);

        // Assert
        $this->assertSame(['github', 'google'], array_column($list, 'provider'));
    }

    public function testTouchingNotesTheSignInAndKeepsTheEmailWhenNoneIsGiven(): void
    {
        // Arrange
        $data = new TestData();
        $identities = new SqliteIdentities($data->db);
        $identities->link($data->user('alice'), 'google', 'g-1', 'alice@example.com');
        $id = $identities->find('google', 'g-1')['id'];

        // Act
        $identities->touch($id, null);

        // Assert
        $row = $identities->find('google', 'g-1');
        $this->assertNotNull($row['last_login_at']);
        $this->assertSame('alice@example.com', $row['email']);
    }

    public function testUnlinkingRemovesOnlyThatProvidersAccount(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $identities = new SqliteIdentities($data->db);
        $identities->link($alice, 'google', 'g-1', null);
        $identities->link($alice, 'github', 'h-1', null);

        // Act
        $removed = $identities->unlink($alice, 'google');
        $again = $identities->unlink($alice, 'google');

        // Assert
        $this->assertTrue($removed);
        $this->assertFalse($again);
        $this->assertSame(['github'], array_column($identities->forUser($alice), 'provider'));
    }

    public function testDeletingThePersonRemovesTheirConnections(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $identities = new SqliteIdentities($data->db);
        $identities->link($alice, 'google', 'g-1', null);

        // Act
        $data->users->delete($alice);

        // Assert
        $this->assertNull($identities->find('google', 'g-1'));
    }
}
