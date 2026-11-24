<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\OAuth\ClientRegistrars;

use PhpSso\OAuth\ClientRegistrars;
use PhpSso\OAuth\StoredRegistrations;
use PhpSso\OAuthServer\Grant\GrantType;
use PhpSso\OAuthServer\Registration\ClientMetadata;
use PhpSso\OAuthServer\Registration\ClientRegistration;
use PhpSso\Store\Sql\SqlScopes;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(ClientRegistrars::class, 'mayRegister')]
#[CoversMethod(ClientRegistrars::class, 'allowedScopes')]
#[CoversMethod(ClientRegistrars::class, 'anyRegistrar')]
final class PolicyTest extends TestCase
{
    private function policy(TestData $data): ClientRegistrars
    {
        return new ClientRegistrars($data->clients, new SqlScopes($data->db, ['openid', 'profile', 'email']));
    }

    public function testOnlyAClientWithTheSettingMayRegister(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $data->client('other');
        $data->clients->setRegistrar('backend', ['openid']);
        $policy = $this->policy($data);

        // Act
        $results = [$policy->mayRegister('backend'), $policy->mayRegister('other'), $policy->mayRegister('nobody')];

        // Assert
        $this->assertSame([true, false, false], $results);
    }

    public function testAnAppThatRegisteredItselfNeverMayRegister(): void
    {
        // Arrange
        $data = new TestData();
        (new StoredRegistrations($data->clients))->save(new ClientRegistration('selfmade', new ClientMetadata(['https://a.example/cb'], ['code'], [GrantType::AuthorizationCode], 'none', ['openid']), null, 'x', 1));
        $data->clients->setRegistrar('selfmade', ['openid']);

        // Act
        $may = $this->policy($data)->mayRegister('selfmade');

        // Assert
        $this->assertFalse($may);
    }

    public function testTheScopesComeFromTheRegistrarsSettingAndMustStillExist(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $data->clients->setRegistrar('backend', ['openid', 'email', 'deleted:scope']);

        // Act
        $scopes = $this->policy($data)->allowedScopes('backend');

        // Assert
        $this->assertSame(['openid', 'email'], $scopes);
    }

    public function testAnyRegistrarFollowsTheClients(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('backend');
        $policy = $this->policy($data);

        // Act
        $before = $policy->anyRegistrar();
        $data->clients->setRegistrar('backend', ['openid']);
        $after = $policy->anyRegistrar();

        // Assert
        $this->assertSame([false, true], [$before, $after]);
    }
}
