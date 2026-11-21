<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use PhpSso\Auth\AccountPolicy;
use PhpSso\Store\Identities;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\Users;
use PhpSso\OAuthServer\Encoding\Base64Url;

/**
 * Signing in with, and connecting, accounts at outside providers. A round trip is bound to the browser that started
 * it (state, nonce and PKCE verifier live in the session and work once), and a provider account is recognised by the
 * provider's own id for the person, never by the email address alone.
 */
final class SocialLogin
{
    private const TTL = 600;

    public function __construct(
        private SocialProviders $providers,
        private Identities $identities,
        private Users $users,
        private AccountPolicy $policy,
        private string $baseUrl,
        private AccountMatcher $matcher,
        private ?SamlConnections $saml = null,
    ) {
    }

    public function policy(): AccountPolicy
    {
        return $this->policy;
    }

    /** The provider's display name. */
    public function labelFor(string $providerKey): string
    {
        if ($this->saml !== null && preg_match('/^saml-(\d+)$/', $providerKey, $m) === 1) {
            return (string) ($this->saml->find((int) $m[1])['name'] ?? 'Single sign-on'); // a company's provider, by the name an administrator gave it
        }

        return $this->providers->labelFor($providerKey);
    }

    /** The address to register at the provider, and where it sends people back. */
    public function redirectUri(string $providerKey): string
    {
        return rtrim($this->baseUrl, '/') . '/login/social/' . $providerKey . '/callback';
    }

    /**
     * Starts a round trip.
     *
     * @param string $intent `login`, or `link` to connect the account to `$sub` (who must be signed in)
     * @return string where to send the browser
     * @throws SocialLoginException
     */
    public function begin(string $providerKey, string $intent = 'login', ?string $sub = null): string
    {
        $provider = $this->providers->get($providerKey) ?? throw new SocialLoginException('That way of signing in is not available.');
        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = Base64Url::encode(random_bytes(32));

        $url = $provider->authorizationUrl($this->redirectUri($providerKey), $state, $nonce, Base64Url::encode(hash('sha256', $verifier, true)));
        $_SESSION['social'] = ['provider' => $providerKey, 'intent' => $intent, 'sub' => $sub, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'expires' => time() + self::TTL];

        return $url;
    }

    /** What the person was doing when they left for the provider, so a failure can send them back to the right page. */
    public function pendingIntent(): string
    {
        return ($_SESSION['social']['intent'] ?? 'login') === 'link' ? 'link' : 'login';
    }

    /**
     * Handles the provider sending the browser back.
     *
     * @param array<string, mixed> $query the address's query parameters
     * @return array{intent: string, user: array<string, mixed>, identity: ExternalIdentity}
     * @throws SocialLoginException
     */
    public function finish(string $providerKey, array $query): array
    {
        $pending = $_SESSION['social'] ?? null;
        unset($_SESSION['social']); // one try per round trip
        $provider = $this->providers->get($providerKey);
        if (!is_array($pending) || $provider === null || ($pending['provider'] ?? null) !== $providerKey || (int) ($pending['expires'] ?? 0) < time()
            || !is_string($pending['state'] ?? null) || !hash_equals($pending['state'], (string) ($query['state'] ?? ''))) {
            throw new SocialLoginException('That sign-in attempt expired or was not started here. Please try again.', 'Missing, expired or mismatched state.');
        }
        if (isset($query['error'])) {
            throw new SocialLoginException("Signing in with {$provider->label()} was cancelled or refused.", 'The provider returned error: ' . (is_string($query['error']) ? $query['error'] : '?'));
        }
        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new SocialLoginException("We could not sign you in with {$provider->label()}.", 'The callback has no code.');
        }

        $identity = $provider->identify($this->redirectUri($providerKey), $code, (string) $pending['verifier'], (string) $pending['nonce']);

        if (($pending['intent'] ?? 'login') === 'link') {
            $user = is_string($pending['sub'] ?? null) ? $this->users->find($pending['sub']) : null;
            if ($user === null) {
                throw new SocialLoginException('Sign in first, then connect the account.', 'No user to link to.');
            }
            $this->matcher->connect($user, $identity);

            return ['intent' => 'link', 'user' => $user, 'identity' => $identity];
        }

        return ['intent' => 'login', 'user' => $this->resolve($identity), 'identity' => $identity];
    }

    /**
     * Disconnects the person's own account, unless it is their only way to sign in.
     *
     * @param array<string, mixed> $user
     * @return string|null why it was not done, or null when it was
     */
    public function unlink(array $user, string $providerKey): ?string
    {
        $linked = $this->identities->forUser($user['sub']);
        if ($user['password_hash'] === null && count($linked) <= 1) {
            return 'That is your only way to sign in, so it cannot be removed.';
        }

        return $this->identities->unlink($user['sub'], $providerKey) ? null : 'That account is not connected.';
    }

    /** An administrator disconnects someone's account. @return bool whether there was one */
    public function unlinkAny(string $sub, string $providerKey): bool
    {
        return $this->identities->unlink($sub, $providerKey);
    }

    /** @return list<array{provider: string, label: string, email: ?string, created_at: int, last_login_at: ?int}> */
    public function connectionsOf(string $sub): array
    {
        return array_map(fn (array $row): array => [
            'provider' => $row['provider'],
            'label' => $this->labelFor($row['provider']),
            'email' => $row['email'],
            'created_at' => (int) $row['created_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (int) $row['last_login_at'],
        ], $this->identities->forUser($sub));
    }

    /**
     * What the account page shows.
     *
     * @param array<string, mixed> $user
     * @return array{linked: list<array<string, mixed>>, available: list<array{key: string, label: string}>, can_unlink: bool}
     */
    public function overview(array $user): array
    {
        $linked = $this->connectionsOf($user['sub']);
        $linkedKeys = array_column($linked, 'provider');

        return [
            'linked' => $linked,
            'available' => array_values(array_filter($this->providers->buttons(), static fn (array $p): bool => !in_array($p['key'], $linkedKeys, true))),
            'can_unlink' => $user['password_hash'] !== null || count($linked) > 1,
        ];
    }

    /** @return list<array{key: string, label: string, enabled: bool, redirect_uri: string}> */
    public function providerStatus(): array
    {
        return array_map(fn (array $p): array => $p + ['redirect_uri' => $this->redirectUri($p['key'])], $this->providers->all());
    }

    /**
     * Who a provider account is: see AccountMatcher. The social settings decide whether the address may connect or create.
     *
     * @return array<string, mixed> the user row
     * @throws SocialLoginException
     */
    private function resolve(ExternalIdentity $identity): array
    {
        $email = $identity->verifiedEmail();
        $policy = $this->policy->current();

        return $this->matcher->match(
            $identity,
            $this->labelFor($identity->provider),
            $policy['link_by_verified_email'],
            $policy['create_users'],
            $email !== null && $this->policy->domainAllowed($email),
        );
    }
}
