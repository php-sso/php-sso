<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginFlow;

use PhpSso\Auth\LoginFlow;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(LoginFlow::class, 'begin')]
#[CoversMethod(LoginFlow::class, 'pending')]
final class BeginTest extends TestCase
{
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $data = new TestData();
        $this->flow = new LoginFlow(new Sessions($data->db, 3600), new LoginAttempts($data->db), new \PhpSso\Auth\Actions\RequiredActions([]), static fn (): bool => true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItRemembersWhoIsSigningIn(): void
    {
        // Arrange
        $this->flow->begin('alice', 'sub-1');

        // Act
        $pending = $this->flow->pending();

        // Assert
        $this->assertSame(['identifier' => 'alice', 'sub' => 'sub-1'], $pending);
    }

    public function testItRemembersAnUnknownIdentifierWithoutAnAccount(): void
    {
        // Arrange
        $this->flow->begin('ghost', null);

        // Act
        $pending = $this->flow->pending();

        // Assert
        $this->assertSame(['identifier' => 'ghost', 'sub' => null], $pending);
    }

    public function testNobodyIsPendingWhenNothingWasBegun(): void
    {
        // Arrange (nothing begun)

        // Act
        $pending = $this->flow->pending();

        // Assert
        $this->assertNull($pending);
    }

    public function testNobodyIsPendingOnceTheTimeIsUp(): void
    {
        // Arrange
        $this->flow->begin('alice', 'sub-1');
        $_SESSION['login']['expires'] = time() - 1;

        // Act
        $pending = $this->flow->pending();

        // Assert
        $this->assertNull($pending);
        $this->assertArrayNotHasKey('login', $_SESSION);
    }

    public function testCompletingTheSignInForgetsWhoWasPending(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $flow = new LoginFlow(new Sessions($data->db, 3600), new LoginAttempts($data->db), new \PhpSso\Auth\Actions\RequiredActions([]), static fn (): bool => true);
        $flow->begin('alice', $sub);

        // Act
        $flow->complete($data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertNull($flow->pending());
    }
}
