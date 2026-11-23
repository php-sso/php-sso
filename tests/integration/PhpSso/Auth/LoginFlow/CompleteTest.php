<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\LoginFlow;

use PhpSso\Auth\LoginFlow;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlLoginAttempts;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(LoginFlow::class, 'complete')]
final class CompleteTest extends TestCase
{
    private TestData $data;
    private Sessions $sessions;
    private LoginAttempts $attempts;
    private int $regenerated = 0;
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->attempts = new SqlLoginAttempts($this->data->db);
        $this->regenerated = 0;
        $this->flow = new LoginFlow($this->sessions, $this->attempts, new \PhpSso\Auth\Actions\RequiredActions([]), function (): bool {
            $this->regenerated++;

            return true;
        });
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItStoresThePersonAndANewLoginSessionInThePhpSession(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $this->flow->complete($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame($sub, $_SESSION['user']);
        $this->assertTrue($this->sessions->touch($_SESSION['sid'], $sub));
        $this->assertSame(['user', 'sid'], array_keys($_SESSION));
    }

    public function testItReturnsTheRememberedAddress(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $_SESSION['return_to'] = '/authorize?client_id=demo';

        // Act
        $returnTo = $this->flow->complete($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/authorize?client_id=demo', $returnTo);
    }

    public function testItReturnsTheHomePageWhenNothingWasRemembered(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $returnTo = $this->flow->complete($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/', $returnTo);
    }

    public function testItReplacesThePhpSessionId(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $this->flow->complete($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame(1, $this->regenerated);
    }

    public function testItRecordsASuccessfulAttemptWithWhatThePersonTyped(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $this->flow->complete($this->data->users->find($sub), 'Alice@Example.com', '203.0.113.9', 'agent');

        // Assert
        $attempt = $this->attempts->recent(1)[0];
        $this->assertSame('Alice@Example.com', $attempt['username']);
        $this->assertSame($sub, $attempt['user_sub']);
        $this->assertSame(1, (int) $attempt['success']);
    }

    public function testItEndsTheOldSessionWhenSomeoneElseSignsInOverIt(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $bob = $this->data->user('bob');
        $old = $this->sessions->start($alice, '203.0.113.9', 'agent');
        $_SESSION = ['user' => $alice, 'sid' => $old];

        // Act
        $this->flow->complete($this->data->users->find($bob), 'bob', '203.0.113.9', 'agent');

        // Assert
        $this->assertFalse($this->sessions->touch($old, $alice));
        $this->assertSame($bob, $_SESSION['user']);
    }

    public function testItDoesNotSignInAnAccountThatWasLockedMeanwhile(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->data->users->setLocked($sub, true);
        $_SESSION = ['login' => ['stage' => 'second_factor']];

        // Act
        $returnTo = $this->flow->complete($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/login', $returnTo);
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertArrayNotHasKey('login', $_SESSION);
    }
}
