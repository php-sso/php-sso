<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginFlow;

use PhpSso\Auth\LoginFlow;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlLoginAttempts;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(LoginFlow::class, 'beginSecondFactor')]
#[CoversMethod(LoginFlow::class, 'secondFactorPending')]
#[CoversMethod(LoginFlow::class, 'markSecondFactorPassed')]
#[CoversMethod(LoginFlow::class, 'pending')]
final class SecondFactorTest extends TestCase
{
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $data = new TestData();
        $this->flow = new LoginFlow(new SqlSessions($data->db, 3600), new SqlLoginAttempts($data->db), new \PhpSso\Auth\Actions\RequiredActions([]), static fn (): bool => true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItRemembersWhoStillHasToPassTheSecondFactor(): void
    {
        // Arrange
        $this->flow->beginSecondFactor('sub-1', 'alice', 'challenge');

        // Act
        $pending = $this->flow->secondFactorPending();

        // Assert
        $this->assertSame(['identifier' => 'alice', 'sub' => 'sub-1', 'step' => 'challenge', 'passed' => false], $pending);
    }

    public function testSomeoneWhoOnlyGaveTheirIdentifierIsNotWaitingForASecondFactor(): void
    {
        // Arrange
        $this->flow->begin('alice', 'sub-1');

        // Act
        $pending = $this->flow->secondFactorPending();

        // Assert
        $this->assertNull($pending);
    }

    public function testSomeoneWaitingForTheSecondFactorCannotReuseThePasswordStep(): void
    {
        // Arrange
        $this->flow->beginSecondFactor('sub-1', 'alice', 'challenge');

        // Act
        $pending = $this->flow->pending();

        // Assert
        $this->assertNull($pending);
        $this->assertNotNull($this->flow->secondFactorPending());
    }

    public function testTheSecondFactorStepExpires(): void
    {
        // Arrange
        $this->flow->beginSecondFactor('sub-1', 'alice', 'challenge');
        $_SESSION['login']['expires'] = time() - 1;

        // Act
        $pending = $this->flow->secondFactorPending();

        // Assert
        $this->assertNull($pending);
        $this->assertArrayNotHasKey('login', $_SESSION);
    }

    public function testMarkingItPassedIsRemembered(): void
    {
        // Arrange
        $this->flow->beginSecondFactor('sub-1', 'alice', 'enroll');

        // Act
        $this->flow->markSecondFactorPassed();

        // Assert
        $this->assertTrue($this->flow->secondFactorPending()['passed']);
    }
}
