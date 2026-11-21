<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\LoginFlow;

use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Auth\LoginFlow;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteLoginAttempts;
use PhpSso\Store\Sqlite\SqliteSessions;

#[CoversMethod(LoginFlow::class, 'proceed')]
#[CoversMethod(LoginFlow::class, 'beginActions')]
#[CoversMethod(LoginFlow::class, 'actionsPending')]
final class ProceedTest extends TestCase
{
    private TestData $data;
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $sessions = new SqliteSessions($this->data->db, 3600);
        $this->flow = new LoginFlow($sessions, new SqliteLoginAttempts($this->data->db), new RequiredActions([new ChangePasswordAction($this->data->users, $sessions)]), static fn (): bool => true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testWithNothingRequiredThePersonIsSignedIn(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $_SESSION['return_to'] = '/account';

        // Act
        $url = $this->flow->proceed($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/account', $url);
        $this->assertSame($sub, $_SESSION['user']);
    }

    public function testWithSomethingRequiredThePersonIsNotSignedInYet(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->data->users->setMustChangePassword($sub, true);

        // Act
        $url = $this->flow->proceed($this->data->users->find($sub), 'alice', '203.0.113.9', 'agent');

        // Assert
        $this->assertSame('/login/required', $url);
        $this->assertArrayNotHasKey('user', $_SESSION);
        $this->assertSame(['identifier' => 'alice', 'sub' => $sub, 'amr' => []], $this->flow->actionsPending());
    }

    public function testNothingIsPendingWhenThePersonIsInAnotherStage(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->flow->begin('alice', $sub);

        // Act
        $pending = $this->flow->actionsPending();

        // Assert
        $this->assertNull($pending);
    }

    public function testThePendingStepExpires(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->flow->beginActions($sub, 'alice');
        $_SESSION['login']['expires'] = time() - 1;

        // Act
        $pending = $this->flow->actionsPending();

        // Assert
        $this->assertNull($pending);
        $this->assertArrayNotHasKey('login', $_SESSION);
    }
}
