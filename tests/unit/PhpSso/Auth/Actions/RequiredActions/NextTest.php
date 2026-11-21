<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Actions\RequiredActions;

use PhpSso\Auth\Actions\ChangePasswordAction;
use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Store\Sessions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(RequiredActions::class, 'next')]
final class NextTest extends TestCase
{
    public function testItReturnsTheFirstActionThatApplies(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $data->users->setMustChangePassword($sub, true);
        $actions = new RequiredActions([new ChangePasswordAction($data->users, new Sessions($data->db, 3600))]);

        // Act
        $next = $actions->next($data->users->find($sub));

        // Assert
        $this->assertSame('change_password', $next?->key());
    }

    public function testItReturnsNullWhenNothingApplies(): void
    {
        // Arrange
        $data = new TestData();
        $sub = $data->user('alice');
        $actions = new RequiredActions([new ChangePasswordAction($data->users, new Sessions($data->db, 3600))]);

        // Act
        $next = $actions->next($data->users->find($sub));

        // Assert
        $this->assertNull($next);
    }
}
