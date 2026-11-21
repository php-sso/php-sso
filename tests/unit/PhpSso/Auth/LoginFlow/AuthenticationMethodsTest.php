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
use PhpSso\Store\Sql\SqlLoginAttempts;
use PhpSso\Store\Sql\SqlSessions;

#[CoversMethod(LoginFlow::class, 'primaryAuthenticated')]
#[CoversMethod(LoginFlow::class, 'proceed')]
#[CoversMethod(LoginFlow::class, 'beginSecondFactor')]
#[CoversMethod(LoginFlow::class, 'markSecondFactorPassed')]
#[CoversMethod(LoginFlow::class, 'beginActions')]
#[CoversMethod(LoginFlow::class, 'complete')]
final class AuthenticationMethodsTest extends TestCase
{
    private TestData $data;
    private Sessions $sessions;
    private LoginFlow $flow;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->sessions = new SqlSessions($this->data->db, 3600);
        $this->flow = new LoginFlow($this->sessions, new SqlLoginAttempts($this->data->db), new RequiredActions([new ChangePasswordAction($this->data->users, $this->sessions)]), static fn (): bool => true);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return list<string>|null */
    private function recorded(): ?array
    {
        return $this->sessions->amr((string) $_SESSION['sid']);
    }

    public function testAPasswordSignInIsRecordedAsPwd(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));

        // Act
        $this->flow->primaryAuthenticated($user, 'alice', 'none', '203.0.113.9', 'agent');

        // Assert
        self::assertSame(['pwd'], $this->recorded());
    }

    public function testAnOutsideAccountIsRecordedAsFedAndNotAsAPassword(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));

        // Act
        $this->flow->primaryAuthenticated($user, 'google:alice', 'none', '203.0.113.9', 'agent', ['fed']);

        // Assert
        self::assertSame(['fed'], $this->recorded());
    }

    public function testASecondFactorIsAddedToTheFirst(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));
        $this->flow->primaryAuthenticated($user, 'alice', 'challenge', '203.0.113.9', 'agent');

        // Act
        $this->flow->proceed($user, 'alice', '203.0.113.9', 'agent', ['otp', 'mfa']);

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $this->recorded());
    }

    public function testAnOutsideAccountWithALocalSecondFactorIsFedOtpMfa(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));
        $this->flow->primaryAuthenticated($user, 'saml-1:alice', 'challenge', '203.0.113.9', 'agent', ['fed']);

        // Act
        $this->flow->proceed($user, 'saml-1:alice', '203.0.113.9', 'agent', ['otp', 'mfa']);

        // Assert
        self::assertSame(['fed', 'otp', 'mfa'], $this->recorded());
    }

    public function testSettingTheAppUpDuringSignInCountsAsHavingUsedIt(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));
        $this->flow->primaryAuthenticated($user, 'alice', 'enroll', '203.0.113.9', 'agent');
        $this->flow->markSecondFactorPassed(['otp', 'mfa']);

        // Act
        $this->flow->proceed($user, 'alice', '203.0.113.9', 'agent');

        // Assert
        self::assertSame(['pwd', 'otp', 'mfa'], $this->recorded());
    }

    public function testChangingTheSecondStepToAChallengeKeepsWhatIsKnown(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));
        $this->flow->primaryAuthenticated($user, 'saml-1:alice', 'enroll', '203.0.113.9', 'agent', ['fed']);

        // Act
        $this->flow->beginSecondFactor($user['sub'], 'saml-1:alice', 'challenge'); // set up in another tab meanwhile
        $this->flow->proceed($user, 'saml-1:alice', '203.0.113.9', 'agent', ['otp', 'mfa']);

        // Assert
        self::assertSame(['fed', 'otp', 'mfa'], $this->recorded());
    }

    public function testWhatHappensBeforeARequiredStepIsKeptThroughIt(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->data->users->setMustChangePassword($sub, true);
        $user = $this->data->users->find($sub);
        $this->flow->primaryAuthenticated($user, 'alice', 'challenge', '203.0.113.9', 'agent');
        $url = $this->flow->proceed($user, 'alice', '203.0.113.9', 'agent', ['otp', 'mfa']);

        // Act
        $pending = $this->flow->actionsPending();
        $this->flow->complete($user, 'alice', '203.0.113.9', 'agent', $pending['amr']);

        // Assert
        self::assertSame('/login/required', $url);
        self::assertSame(['pwd', 'otp', 'mfa'], $pending['amr']);
        self::assertSame(['pwd', 'otp', 'mfa'], $this->recorded());
    }

    public function testMethodNamesAreCleanedAndEachKeptOnce(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));

        // Act
        $this->flow->primaryAuthenticated($user, 'alice', 'none', '203.0.113.9', 'agent', ['pwd', '', 'pwd', 'mfa']);

        // Assert
        self::assertSame(['pwd', 'mfa'], $this->recorded());
    }

    public function testNothingIsRecordedWhenNoMethodIsKnown(): void
    {
        // Arrange
        $user = $this->data->users->find($this->data->user('alice'));

        // Act
        $this->flow->complete($user, 'alice', '203.0.113.9', 'agent');

        // Assert
        self::assertNull($this->recorded());
    }
}
