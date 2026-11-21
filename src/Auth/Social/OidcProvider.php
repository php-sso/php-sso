<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use PhpSso\OAuthServer\Clock\SystemClock;
use PhpSso\OAuthServer\Jwt\ClaimsValidator;
use PhpSso\OAuthServer\Jwt\InvalidJwt;
use PhpSso\OAuthServer\Jwt\JwtDecoder;
use PhpSso\OAuthServer\Jwt\VerificationKeySet;
use PhpSso\Store\HttpCache;
use Psr\Clock\ClockInterface;

/**
 * Any OpenID Connect provider (Google, Microsoft, Okta, Keycloak...), through the authorization code flow with PKCE.
 * The ID token is only believed after its signature (RS256 keys from the provider's JWKS), issuer, audience, expiry
 * and nonce check out. Signatures and times are checked by php-sso/oauth-server, which accepts RS256 only and never lets the
 * token choose how it is verified.
 */
final class OidcProvider implements SocialProvider
{
    private const FAILED = 'We could not sign you in with that provider.';

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;
    /** @var array<string, mixed>|null */
    private ?array $jwks = null;
    /** Whether the keys in use came from the saved copy, which may be out of date after the provider changed its keys. */
    private bool $jwksFromCache = false;

    /**
     * @param string[] $scopes
     * @param bool $allowHttp accept http:// endpoints (local development only)
     */
    public function __construct(
        private string $key,
        private string $label,
        private string $issuer,
        private string $clientId,
        private string $clientSecret,
        private HttpJson $http,
        private array $scopes = ['openid', 'email', 'profile'],
        private bool $allowHttp = false,
        private ?HttpCache $cache = null,
        private int $cacheSeconds = 3600,
        private int $refetchKeysAfter = 300,
        private ?ClockInterface $clock = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        $endpoint = $this->endpoint('authorization_endpoint');

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function identify(string $redirectUri, string $code, string $codeVerifier, string $nonce): ExternalIdentity
    {
        $tokens = $this->http->postForm($this->endpoint('token_endpoint'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
        ]);
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new SocialLoginException(self::FAILED, 'The token response has no id_token.');
        }

        $claims = $this->verified($idToken, $nonce);

        return new ExternalIdentity(
            $this->key,
            (string) $claims['sub'],
            isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null,
            filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
        );
    }

    /**
     * @return array<string, mixed> the claims of an ID token that can be trusted
     * @throws SocialLoginException
     */
    private function verified(string $idToken, string $nonce): array
    {
        $keys = $this->jwks();
        try {
            $claims = $this->decode($idToken, $keys);
        } catch (SocialLoginException $e) {
            // A token signed with a key we do not know may just mean the provider changed its keys since we saved them:
            // look again, but not more than once in a while, so bad tokens cannot make us fetch on every request.
            if (!$this->jwksFromCache || !$this->mayFetchKeysAgain()) {
                throw $e;
            }
            $claims = $this->decode($idToken, $this->jwks(refresh: true));
        }

        $audience = (array) ($claims['aud'] ?? []);
        $problem = match (true) {
            ($claims['iss'] ?? null) !== $this->issuer => 'wrong issuer',
            !in_array($this->clientId, $audience, true) => 'not meant for this client',
            count($audience) > 1 && ($claims['azp'] ?? null) !== $this->clientId => 'wrong authorized party',
            !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce']) => 'wrong nonce',
            !is_string($claims['sub'] ?? null) || $claims['sub'] === '' => 'no subject',
            default => null,
        };
        if ($problem !== null) {
            throw new SocialLoginException(self::FAILED, 'The ID token was refused: ' . $problem);
        }

        try {
            // Expiry, not-before and issued-at, with a minute of leeway because clocks differ a little.
            (new ClaimsValidator($this->clock ?? new SystemClock(), $this->issuer, 60))->validate($claims, $this->clientId);
        } catch (InvalidJwt $e) {
            throw new SocialLoginException(self::FAILED, 'The ID token was refused: ' . $e->getMessage());
        }

        return $claims;
    }

    /**
     * Checks the signature. Keys are RS256 only, so a token that claims another algorithm (none, HS256 with the public key)
     * is refused, and so is one that names no key or a key we do not have.
     *
     * @param array<string, mixed> $keys
     * @return array<string, mixed> the claims, whose signature is good (nothing else is judged yet)
     * @throws SocialLoginException
     */
    private function decode(string $idToken, array $keys): array
    {
        try {
            return (new JwtDecoder(VerificationKeySet::fromJwks($keys)))->decode($idToken)['claims'];
        } catch (InvalidJwt $e) {
            throw new SocialLoginException(self::FAILED, 'The ID token was refused: ' . $e->getMessage());
        }
    }

    private function mayFetchKeysAgain(): bool
    {
        $entry = $this->cache?->entry($this->jwksCacheKey());

        return $entry === null || $entry['fetched_at'] <= time() - $this->refetchKeysAfter;
    }

    private function endpoint(string $name): string
    {
        $url = $this->metadata()[$name] ?? null;
        if (!is_string($url)) {
            throw new SocialLoginException(self::FAILED, "The provider's configuration has no {$name}.");
        }

        return $this->checkedUrl($url);
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        if ($this->metadata === null) {
            $cacheKey = 'oidc-metadata:' . $this->issuer;
            $metadata = $this->cache?->get($cacheKey, $this->cacheSeconds);
            if ($metadata === null) {
                $metadata = $this->http->get($this->checkedUrl(rtrim($this->issuer, '/') . '/.well-known/openid-configuration'));
                if (($metadata['issuer'] ?? null) !== $this->issuer) {
                    throw new SocialLoginException(self::FAILED, 'The provider names itself differently than it is configured.');
                }
                $this->cache?->put($cacheKey, $metadata); // only after it checked out
            }
            $this->metadata = $metadata;
        }

        return $this->metadata;
    }

    /** @return array<string, mixed> */
    private function jwks(bool $refresh = false): array
    {
        if ($this->jwks !== null && !$refresh) {
            return $this->jwks;
        }
        $saved = $refresh ? null : $this->cache?->get($this->jwksCacheKey(), $this->cacheSeconds);
        $this->jwksFromCache = $saved !== null;
        $this->jwks = $saved ?? $this->http->get($this->endpoint('jwks_uri'));
        if ($saved === null) {
            $this->cache?->put($this->jwksCacheKey(), $this->jwks);
        }

        return $this->jwks;
    }

    private function jwksCacheKey(): string
    {
        return 'oidc-jwks:' . $this->issuer;
    }

    private function checkedUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' && !($this->allowHttp && $scheme === 'http')) {
            throw new SocialLoginException(self::FAILED, "Refusing a provider address that is not https: {$url}");
        }

        return $url;
    }
}
