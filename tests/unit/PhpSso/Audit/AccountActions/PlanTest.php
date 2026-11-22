<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AccountActions;

use PhpSso\Audit\AccountActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AccountActions::class, 'plan')]
final class PlanTest extends TestCase
{
    public function testAPathThatChangesTheAccountHasAnActionName(): void
    {
        // Arrange
        $path = '/password';

        // Act
        $plan = AccountActions::plan($path);

        // Assert
        $this->assertSame('account.password_change', $plan['action']);
        $this->assertSame('flash', $plan['expect']);
    }

    public function testTheScreensThatShowRecoveryCodesAreKnownByTheirPage(): void
    {
        // Arrange
        $paths = ['/mfa/setup', '/mfa/recovery'];

        // Act
        $plans = array_map(AccountActions::plan(...), $paths);

        // Assert
        $this->assertSame(['page', 'page'], array_column($plans, 'expect'));
    }

    public function testUnlinkingKeepsTheProviderAndEndingASessionNamesIt(): void
    {
        // Arrange
        $sid = str_repeat('ab', 16);

        // Act
        $unlink = AccountActions::plan('/social/github/unlink');
        $end = AccountActions::plan('/sessions/' . $sid . '/end');

        // Assert
        $this->assertSame(['provider' => 'github'], $unlink['detail']);
        $this->assertSame('account.session_end', $end['action']);
        $this->assertSame($sid, $end['target']);
    }

    public function testAnythingElseIsNotRecorded(): void
    {
        // Arrange
        $paths = ['/', '/social/github/link', '/nonsense'];

        // Act
        $plans = array_map(AccountActions::plan(...), $paths);

        // Assert
        $this->assertSame([null, null, null], $plans);
    }
}
