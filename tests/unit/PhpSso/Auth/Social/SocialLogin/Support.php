<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\ExternalIdentity;
use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\AccountPolicy;
use PhpSso\Auth\Social\SocialProviders;
use PhpSso\Store\Identities;
use PhpSso\Store\Settings;
use Tests\unit\PhpSso\Support\TestData;

/** Social sign-in on an in-memory database with a fake provider. */
final class Support
{
    public readonly TestData $data;
    public readonly Identities $identities;
    public readonly FakeProvider $provider;
    public readonly SocialProviders $providers;
    public readonly SocialLogin $social;

    /** @param array<string, mixed> $policy starting values of the policy (link_by_verified_email, create_users, allowed_domains) */
    public function __construct(array $policy = [], ?TestData $data = null)
    {
        $this->data = $data ?? new TestData();
        $this->identities = new Identities($this->data->db);
        $this->provider = new FakeProvider();
        $this->providers = new SocialProviders(['fake' => $this->provider], [['key' => 'fake', 'label' => 'Fake', 'enabled' => true]]);
        $this->social = new SocialLogin($this->providers, $this->identities, $this->data->users, new AccountPolicy(new Settings($this->data->db), $policy), 'https://sso.example.com', new \PhpSso\Auth\Social\AccountMatcher($this->identities, $this->data->users), new \PhpSso\Store\SamlConnections($this->data->db));
        $_SESSION = [];
    }

    public static function identity(string $subject = 'sub-1', ?string $email = 'alice@example.com', bool $verified = true, ?string $name = 'Alice'): ExternalIdentity
    {
        return new ExternalIdentity('fake', $subject, $email, $verified, $name);
    }

    /** A whole round trip: the person leaves, the provider sends them back with the right state. @return array{intent: string, user: array<string, mixed>, identity: ExternalIdentity} */
    public function roundTrip(?ExternalIdentity $identity = null, string $intent = 'login', ?string $sub = null): array
    {
        $this->provider->identity = $identity ?? self::identity();
        $this->social->begin('fake', $intent, $sub);

        return $this->social->finish('fake', ['state' => $this->provider->lastAuthorization['state'], 'code' => 'code-1']);
    }
}
