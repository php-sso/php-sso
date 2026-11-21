<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Database;

use PhpSso\Store\Sqlite\Database;
use PhpSso\Store\DuplicateValue;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Database::class, 'enforceUniqueEmail')]
final class EnforceUniqueEmailTest extends TestCase
{
    public function testItRejectsASecondPersonWithTheSameAddressIgnoringCase(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $data->db->enforceUniqueEmail(true);
        $this->expectException(DuplicateValue::class);

        // Act
        $data->users->create(['username' => 'other', 'password' => 'secret-pass', 'name' => 'Other', 'email' => 'ALICE@example.com', 'email_verified' => false, 'is_admin' => false]);

        // Assert (the exception above)
    }

    public function testItFailsAndNamesTheAddressesThatAreAlreadyShared(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $data->users->create(['username' => 'twin', 'password' => 'secret-pass', 'name' => 'Twin', 'email' => 'Alice@Example.com', 'email_verified' => false, 'is_admin' => false]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alice@example.com');

        // Act
        $data->db->enforceUniqueEmail(true);

        // Assert (the exception above)
    }

    public function testItAllowsSharedAddressesAgainWhenTurnedOff(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');
        $data->db->enforceUniqueEmail(true);
        $data->db->enforceUniqueEmail(false);

        // Act
        $sub = $data->users->create(['username' => 'twin', 'password' => 'secret-pass', 'name' => 'Twin', 'email' => 'alice@example.com', 'email_verified' => false, 'is_admin' => false]);

        // Assert
        $this->assertNotSame('', $sub);
    }
}
