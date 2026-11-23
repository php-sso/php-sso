<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\LoginAttempts;

use PhpSso\Store\LoginAttempts;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlLoginAttempts;

#[CoversMethod(LoginAttempts::class, 'record')]
final class RecordTest extends TestCase
{
    public function testAnAttemptIsAboutThePasswordUnlessSaidOtherwise(): void
    {
        // Arrange
        $attempts = new SqlLoginAttempts((new TestData())->db);

        // Act
        $attempts->record('alice', null, false, '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('password', $attempts->recent(1)[0]['factor']);
    }

    public function testAWrongCodeIsRecordedAsACodeAttempt(): void
    {
        // Arrange
        $attempts = new SqlLoginAttempts((new TestData())->db);

        // Act
        $attempts->record('alice', null, false, '203.0.113.9', 'agent', factor: 'code');

        // Assert
        $this->assertSame('code', $attempts->recent(1)[0]['factor']);
    }

    public function testAnUnknownFactorIsStoredAsPasswordSoTheColumnStaysTidy(): void
    {
        // Arrange
        $attempts = new SqlLoginAttempts((new TestData())->db);

        // Act
        $attempts->record('alice', null, false, '203.0.113.9', 'agent', factor: 'something-else');

        // Assert
        $this->assertSame('password', $attempts->recent(1)[0]['factor']);
    }

    public function testACodeFailureStillCountsTowardsTheSameLimit(): void
    {
        // Arrange
        $data = new TestData();
        $attempts = new SqlLoginAttempts($data->db);
        $sub = $data->user('alice');
        $attempts->record('alice', $sub, false, '203.0.113.9', 'agent');
        $attempts->record('alice', $sub, false, '203.0.113.9', 'agent', factor: 'code');

        // Act
        $failures = $attempts->failureTimesForIdentifier('alice', $sub, 0);

        // Assert
        $this->assertCount(2, $failures);
    }
}
