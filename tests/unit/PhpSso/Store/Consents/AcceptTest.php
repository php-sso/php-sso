<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Consents;

use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlConsents;

#[CoversMethod(Consents::class, 'accept')]
#[CoversMethod(Consents::class, 'withdraw')]
final class AcceptTest extends TestCase
{
    public function testAnAcceptanceKeepsACopyOfWhatWasShown(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', 'https://example.com/terms', true);
        $sub = $data->user('alice');

        // Act
        $accepted = $consents->accept($sub, $id, 1, '203.0.113.9', 1700000000);
        $consents->update($id, 'Renamed', 'Other words.', null, true);

        // Assert
        $row = $data->db->pdo->query('SELECT * FROM user_consents')->fetch();
        $this->assertTrue($accepted);
        $this->assertSame([$sub, 1, 1700000000, '203.0.113.9', 'Terms', 'Please read.', 'https://example.com/terms'], [$row['user_sub'], (int) $row['version'], (int) $row['accepted_at'], $row['ip'], $row['name'], $row['message'], $row['url']]);
    }

    public function testAcceptingAVersionThatIsNoLongerCurrentIsRefused(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $consents->bump($id);
        $sub = $data->user('alice');

        // Act
        $accepted = $consents->accept($sub, $id, 1, '203.0.113.9');

        // Assert
        $this->assertFalse($accepted);
        $this->assertCount(1, $consents->pendingFor($sub));
    }

    public function testAcceptingASwitchedOffConsentIsRefused(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, false);
        $sub = $data->user('alice');

        // Act
        $accepted = $consents->accept($sub, $id, 1, '203.0.113.9');

        // Assert
        $this->assertFalse($accepted);
    }

    public function testWithdrawingAsksThatPersonAgainButKeepsTheRecord(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $alice = $data->user('alice');
        $bob = $data->user('bob');
        $consents->accept($alice, $id, 1, '203.0.113.9');
        $consents->accept($bob, $id, 1, '203.0.113.9');

        // Act
        $consents->withdraw($alice, $id);

        // Assert
        $this->assertCount(1, $consents->pendingFor($alice));
        $this->assertSame([], $consents->pendingFor($bob));
        $this->assertSame(2, (int) $data->db->pdo->query('SELECT COUNT(*) FROM user_consents')->fetchColumn());
    }

    public function testAcceptingAgainAfterWithdrawingWorks(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqlConsents($data->db);
        $id = $consents->create('Terms', 'Please read.', null, true);
        $sub = $data->user('alice');
        $consents->accept($sub, $id, 1, '203.0.113.9');
        $consents->withdraw($sub, $id);

        // Act
        $consents->accept($sub, $id, 1, '203.0.113.9');

        // Assert
        $this->assertSame([], $consents->pendingFor($sub));
    }
}
