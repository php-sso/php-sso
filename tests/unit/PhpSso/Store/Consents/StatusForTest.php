<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Consents;

use PhpSso\Store\Consents;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteConsents;

#[CoversMethod(Consents::class, 'statusFor')]
#[CoversMethod(Consents::class, 'all')]
final class StatusForTest extends TestCase
{
    public function testItReportsAcceptedOutdatedAndWaiting(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqliteConsents($data->db);
        $terms = $consents->create('Terms', 'a', null, true);
        $policy = $consents->create('Policy', 'b', null, true);
        $consents->create('Privacy', 'c', null, true);
        $consents->create('Hidden', 'd', null, false);
        $sub = $data->user('alice');
        $consents->accept($sub, $terms, 1, '203.0.113.9');
        $consents->accept($sub, $policy, 1, '203.0.113.9');
        $consents->bump($policy);

        // Act
        $status = $consents->statusFor($sub);

        // Assert
        $this->assertSame(['Terms' => 'accepted', 'Policy' => 'outdated', 'Privacy' => 'waiting'], array_column($status, 'state', 'name'));
    }

    public function testAllCountsPeopleWhoAcceptedTheCurrentVersion(): void
    {
        // Arrange
        $data = new TestData();
        $consents = new SqliteConsents($data->db);
        $id = $consents->create('Terms', 'a', null, true);
        $consents->accept($data->user('alice'), $id, 1, '203.0.113.9');
        $consents->accept($data->user('bob'), $id, 1, '203.0.113.9');
        $consents->bump($id);
        $consents->accept($data->user('carol'), $id, 2, '203.0.113.9');

        // Act
        $all = $consents->all();

        // Assert
        $this->assertSame(1, (int) $all[0]['accepted']);
    }
}
