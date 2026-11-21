<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Consents;

use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteConsents;

#[CoversMethod(Consents::class, 'delete')]
final class DeleteTest extends TestCase
{
    public function testAnUnusedConsentCanBeDeleted(): void
    {
        // Arrange
        $consents = new SqliteConsents((new TestData())->db);
        $id = $consents->create('Terms', 'a', null, true);

        // Act
        $deleted = $consents->delete($id);

        // Assert
        $this->assertTrue($deleted);
        $this->assertNull($consents->find($id));
    }

    public function testAConsentSomeoneAcceptedIsKeptAsARecord(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqliteConsents($data->db);
        $id = $consents->create('Terms', 'a', null, true);
        $consents->accept($data->user('alice'), $id, 1, '203.0.113.9');

        // Act
        $deleted = $consents->delete($id);

        // Assert
        $this->assertFalse($deleted);
        $this->assertNotNull($consents->find($id));
    }
}
