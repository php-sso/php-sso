<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginFlow;

use PhpSso\Auth\LoginFlow;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginFlow::class, 'primaryAuthenticated')]
final class PrimaryAuthenticatedTest extends TestCase
{
    private TestData $data;
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->flow = new LoginFlow(new Sessions($this->data->db, 3600), new LoginAttempts($this->data->db), new \PhpSso\Auth\Actions\RequiredActions([]), static fn (): bool => true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testWithNoSecondFactorThePersonIsSignedInAndSentOn(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $_SESSION['return_to'] = '/account';

        // Act
        $url = $this->flow->primaryAuthenticated($this->data->users->find($sub), 'alice', 'none', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/account', $url);
        $this->assertSame($sub, $_SESSION['user']);
    }

    public function testAChallengeLeavesThePersonSignedOutAndSendsThemToTheCodePage(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $url = $this->flow->primaryAuthenticated($this->data->users->find($sub), 'alice', 'challenge', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/login/mfa', $url);
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame('challenge', $this->flow->secondFactorPending()['step']);
    }

    public function testASetupStepSendsThePersonToTheSetupPage(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $url = $this->flow->primaryAuthenticated($this->data->users->find($sub), 'alice', 'enroll', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/login/mfa/setup', $url);
        $this->assertSame('enroll', $this->flow->secondFactorPending()['step']);
    }
}
