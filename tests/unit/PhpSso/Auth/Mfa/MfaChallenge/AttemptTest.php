<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\MfaChallenge;

use PhpSso\Auth\Mfa\MfaChallenge;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Auth\LoginController\Support;

#[CoversMethod(MfaChallenge::class, 'attempt')]
final class AttemptTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItAcceptsARightCodeWithoutRecordingAFailure(): void
    {
        // Arrange
        $app = new Support();
        [$user, $secret] = $app->enrolledUser();
        $challenge = new MfaChallenge($app->mfa, $app->throttle, $app->attempts);

        // Act
        $result = $challenge->attempt($user, 'alice', Support::codeFor($secret), '203.0.113.9', 'agent');

        // Assert
        $this->assertTrue($result->accepted);
        $this->assertSame([], $app->attempts->failureTimesForIdentifier('alice', $user['sub'], 0));
    }

    public function testItRecordsAWrongCodeAndSaysSo(): void
    {
        // Arrange
        $app = new Support();
        [$user] = $app->enrolledUser();
        $challenge = new MfaChallenge($app->mfa, $app->throttle, $app->attempts);

        // Act
        $result = $challenge->attempt($user, 'alice', '000000', '203.0.113.9', 'agent');

        // Assert
        $this->assertFalse($result->accepted);
        $this->assertFalse($result->lockedOut());
        $this->assertSame('That code is not right.', $result->message());
        $this->assertCount(1, $app->attempts->failureTimesForIdentifier('alice', $user['sub'], 0));
        $this->assertSame('code', $app->attempts->recent(1)[0]['factor']);
    }

    public function testItChecksNothingOncePastTheLimitAndRecordsTheRefusal(): void
    {
        // Arrange
        $app = new Support(['per_identifier' => 1, 'per_ip' => 100, 'window_seconds' => 900]);
        [$user, $secret] = $app->enrolledUser();
        $challenge = new MfaChallenge($app->mfa, $app->throttle, $app->attempts);
        $challenge->attempt($user, 'alice', '000000', '203.0.113.9', 'agent');

        // Act
        $result = $challenge->attempt($user, 'alice', Support::codeFor($secret), '203.0.113.9', 'agent');

        // Assert
        $this->assertFalse($result->accepted);
        $this->assertTrue($result->lockedOut());
        $this->assertStringContainsString('Too many failed attempts', $result->message());
        $this->assertCount(1, $app->attempts->recentThrottled(10));
    }
}
