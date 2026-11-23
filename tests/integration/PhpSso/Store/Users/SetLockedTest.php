<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\UserState;

#[CoversMethod(Users::class, 'setLocked')]
#[CoversMethod(Users::class, 'isLocked')]
final class SetLockedTest extends TestCase
{
    public function testANewAccountIsNotLocked(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');

        // Act
        $locked = UserState::isLocked($data->users->find($sub));

        // Assert
        $this->assertFalse($locked);
    }

    public function testLockingMarksTheAccountLocked(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');

        // Act
        $data->users->setLocked($sub, true);

        // Assert
        $this->assertTrue(UserState::isLocked($data->users->find($sub)));
    }

    public function testUnlockingClearsTheLock(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->users->setLocked($sub, true);

        // Act
        $data->users->setLocked($sub, false);

        // Assert
        $this->assertFalse(UserState::isLocked($data->users->find($sub)));
    }
}
