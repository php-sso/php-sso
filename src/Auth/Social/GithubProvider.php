<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

/**
 * GitHub, which is OAuth 2.0 and not OpenID Connect: the person is read from the API with the access token. The
 * email address only counts as verified when it is the primary address and GitHub has verified it.
 */
final class GithubProvider implements SocialProvider
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private HttpJson $http,
        private string $webBase = 'https://github.com',
        private string $apiBase = 'https://api.github.com',
    ) {
    }

    public function key(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub';
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        return rtrim($this->webBase, '/') . '/login/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user user:email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identify(string $redirectUri, string $code, string $codeVerifier, string $nonce): ExternalIdentity
    {
        $token = $this->http->postForm(rtrim($this->webBase, '/') . '/login/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            // GitHub answers 200 with an `error` field when the code is wrong.
            throw new SocialLoginException('We could not sign you in with GitHub.', 'No access token: ' . (is_string($token['error'] ?? null) ? $token['error'] : 'unknown'));
        }

        $headers = ['Authorization' => 'Bearer ' . $accessToken, 'User-Agent' => 'php-sso', 'X-GitHub-Api-Version' => '2022-11-28'];
        $profile = $this->http->get(rtrim($this->apiBase, '/') . '/user', $headers);
        if (!isset($profile['id']) || (!is_int($profile['id']) && !is_string($profile['id']))) {
            throw new SocialLoginException('We could not sign you in with GitHub.', 'The profile has no id.');
        }

        $email = null;
        foreach ($this->http->get(rtrim($this->apiBase, '/') . '/user/emails', $headers) as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
                $email = $entry['email'];
                break;
            }
        }

        $name = is_string($profile['name'] ?? null) && $profile['name'] !== '' ? $profile['name'] : (is_string($profile['login'] ?? null) ? $profile['login'] : null);

        return new ExternalIdentity('github', (string) $profile['id'], $email, $email !== null, $name);
    }
}
