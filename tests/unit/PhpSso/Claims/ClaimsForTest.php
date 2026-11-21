<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Claims;

use PhpSso\Claims;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Claims::class, 'claimsFor')]
final class ClaimsForTest extends TestCase
{
    public function testItReleasesTheClaimsOfTheGrantedScopes(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'profile' => ['name'], 'email' => ['email', 'email_verified']]);

        // Act
        $released = $claims->claimsFor($alice, ['openid', 'profile', 'email']);

        // Assert
        $this->assertSame(['name' => 'Alice', 'email' => 'alice@example.com', 'email_verified' => true], $released);
    }

    public function testTheRolesScopeAddsNoClaimHere(): void
    {
        // Arrange
        $data = new TestData();
        $alice = $data->user('alice');
        $claims = new Claims($data->users, $data->roles, ['openid' => [], 'roles' => []]);

        // Act
        $released = $claims->claimsFor($alice, ['openid', 'roles']);

        // Assert
        $this->assertSame([], $released);
    }

    public function testItReturnsNullForAPersonWhoNoLongerExists(): void
    {
        // Arrange
        $data = new TestData();
        $claims = new Claims($data->users, $data->roles, ['openid' => []]);

        // Act
        $released = $claims->claimsFor('missing', ['openid']);

        // Assert
        $this->assertNull($released);
    }
}
