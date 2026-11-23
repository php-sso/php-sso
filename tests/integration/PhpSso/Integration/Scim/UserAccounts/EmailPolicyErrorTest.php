<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Integration\Scim\UserAccounts;

use PhpSso\Integration\Scim\UserAccounts;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\ScimServer\Support;

#[CoversMethod(UserAccounts::class, 'emailPolicyError')]
final class EmailPolicyErrorTest extends TestCase
{
    private function accounts(Support $app): UserAccounts
    {
        return new UserAccounts($app->data->users, $app->sessions, new \PhpSso\Auth\UserRules($app->data->users, 'email'), $app->policy);
    }

    public function testEveryAddressIsAcceptedWhenNoDomainsAreListed(): void
    {
        // Arrange
        $accounts = $this->accounts(new Support());

        // Act
        $error = $accounts->emailPolicyError('anyone@anywhere.example');

        // Assert
        $this->assertNull($error);
    }

    public function testAnAddressInAnAllowedDomainIsAccepted(): void
    {
        // Arrange
        $accounts = $this->accounts(new Support(allowedDomains: ['acme.com']));

        // Act
        $error = $accounts->emailPolicyError('Alice@ACME.com');

        // Assert
        $this->assertNull($error);
    }

    public function testAnAddressOutsideTheAllowedDomainsIsRefused(): void
    {
        // Arrange
        $accounts = $this->accounts(new Support(allowedDomains: ['acme.com']));

        // Act
        $error = $accounts->emailPolicyError('alice@evil.example');

        // Assert
        $this->assertNotNull($error);
        $this->assertStringContainsString('not allowed', $error);
    }
}
