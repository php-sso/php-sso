<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\SocialProviders;

use PhpSso\Auth\Social\GithubProvider;
use PhpSso\Auth\Social\HttpJson;
use PhpSso\Auth\Social\OidcProvider;
use PhpSso\Auth\Social\SocialProviders;
use InvalidArgumentException;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeHttpClient;

#[CoversMethod(SocialProviders::class, 'fromConfig')]
#[CoversMethod(SocialProviders::class, 'buttons')]
#[CoversMethod(SocialProviders::class, 'all')]
#[CoversMethod(SocialProviders::class, 'labelFor')]
final class FromConfigTest extends TestCase
{
    private function http(): HttpJson
    {
        return new HttpJson(new FakeHttpClient(), new RequestFactory(), new StreamFactory());
    }

    public function testOnlyProvidersWithAClientIdAndSecretAreOffered(): void
    {
        // Arrange
        $config = [
            'google' => ['type' => 'oidc', 'label' => 'Google', 'issuer' => 'https://accounts.google.com', 'client_id' => 'id', 'client_secret' => 'secret'],
            'github' => ['type' => 'github', 'label' => 'GitHub', 'client_id' => '', 'client_secret' => ''],
            'half' => ['type' => 'github', 'client_id' => 'id', 'client_secret' => ''],
        ];

        // Act
        $providers = SocialProviders::fromConfig($config, $this->http());

        // Assert
        $this->assertSame([['key' => 'google', 'label' => 'Google']], $providers->buttons());
        $this->assertInstanceOf(OidcProvider::class, $providers->get('google'));
        $this->assertNull($providers->get('github'));
        $this->assertSame([true, false, false], array_column($providers->all(), 'enabled'));
    }

    public function testAnOidcProviderWithoutAnIssuerIsNotOffered(): void
    {
        // Arrange
        $config = ['oidc' => ['type' => 'oidc', 'issuer' => '', 'client_id' => 'id', 'client_secret' => 'secret']];

        // Act
        $providers = SocialProviders::fromConfig($config, $this->http());

        // Assert
        $this->assertSame([], $providers->buttons());
    }

    public function testItBuildsGithubForTheGithubType(): void
    {
        // Arrange
        $config = ['github' => ['type' => 'github', 'client_id' => 'id', 'client_secret' => 'secret']];

        // Act
        $providers = SocialProviders::fromConfig($config, $this->http());

        // Assert
        $this->assertInstanceOf(GithubProvider::class, $providers->get('github'));
    }

    public function testItRefusesAKeyThatCouldNotBePartOfAnAddress(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        SocialProviders::fromConfig(['Bad Key!' => ['type' => 'github', 'client_id' => 'i', 'client_secret' => 's']], $this->http());

        // Assert (the exception above)
    }

    public function testItRefusesAnUnknownTypeOnceItIsConfigured(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        SocialProviders::fromConfig(['x' => ['type' => 'saml', 'client_id' => 'i', 'client_secret' => 's']], $this->http());

        // Assert (the exception above)
    }

    public function testItKnowsTheNameOfAProviderThatWasRemovedFromTheConfig(): void
    {
        // Arrange
        $providers = SocialProviders::fromConfig(['google' => ['label' => 'Google', 'type' => 'github']], $this->http());

        // Act
        $known = $providers->labelFor('google');
        $removed = $providers->labelFor('okta');

        // Assert
        $this->assertSame(['Google', 'Okta'], [$known, $removed]);
    }
}
