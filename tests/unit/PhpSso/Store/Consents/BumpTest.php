<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Consents;

use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteConsents;

#[CoversMethod(Consents::class, 'bump')]
#[CoversMethod(Consents::class, 'update')]
final class BumpTest extends TestCase
{
    public function testBumpingAsksEveryoneAgain(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqliteConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $sub = $data->user('alice');
        $consents->accept($sub, $id, 1, '203.0.113.9');

        // Act
        $version = $consents->bump($id);

        // Assert
        $this->assertSame(2, $version);
        $this->assertCount(1, $consents->pendingFor($sub));
    }

    public function testBumpingAnUnknownConsentReturnsNull(): void
    {
        // Arrange
        $consents = new SqliteConsents((new TestData())->db);

        // Act
        $version = $consents->bump(99);

        // Assert
        $this->assertNull($version);
    }

    public function testEditingTheTextDoesNotAskAnyoneAgain(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqliteConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $sub = $data->user('alice');
        $consents->accept($sub, $id, 1, '203.0.113.9');

        // Act
        $consents->update($id, 'Terms of use', 'Fixed a typo.', 'https://example.com/terms', true);

        // Assert
        $this->assertSame([], $consents->pendingFor($sub));
        $this->assertSame(1, (int) $consents->find($id)['version']);
    }
}
