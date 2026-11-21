<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\OidcProvider;

use PhpSso\Auth\Social\HttpJson;
use PhpSso\Auth\Social\OidcProvider;
use PhpSso\OAuthServer\Encoding\Base64Url;
use PhpSso\Store\HttpCache;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use Tests\unit\PhpSso\Support\FakeHttpClient;

/** A pretend OpenID provider: discovery, keys, a token endpoint, and ID tokens signed with a real RSA key. */
final class Fixture
{
    public const ISSUER = 'https://idp.example.com';
    public const CLIENT_ID = 'client-123';
    public const REDIRECT = 'https://sso.example.com/login/social/idp/callback';

    private static ?\OpenSSLAsymmetricKey $key = null;
    private static ?\OpenSSLAsymmetricKey $otherKey = null;

    public readonly FakeHttpClient $http;
    public readonly OidcProvider $provider;

    /** @param array<string, mixed> $metadata */
    public function __construct(array $metadata = [], bool $allowHttp = false, string $issuer = self::ISSUER, ?HttpCache $cache = null)
    {
        $this->http = new FakeHttpClient([
            $issuer . '/.well-known/openid-configuration' => [200, $metadata + [
                'issuer' => $issuer,
                'authorization_endpoint' => $issuer . '/authorize',
                'token_endpoint' => $issuer . '/token',
                'jwks_uri' => $issuer . '/jwks',
            ]],
            $issuer . '/jwks' => [200, ['keys' => [$this->jwk(self::key(), 'kid-1')]]],
        ]);
        $this->provider = new OidcProvider('idp', 'Example IdP', $issuer, self::CLIENT_ID, 'secret-xyz', new HttpJson($this->http, new RequestFactory(), new StreamFactory()), allowHttp: $allowHttp, cache: $cache);
    }

    /** A key set that does not hold the provider's key under its id: what a saved copy looks like after the provider changed its keys. @return array<string, mixed> */
    public function outdatedKeySet(): array
    {
        return ['keys' => [$this->jwk(self::otherKey(), 'kid-1')]];
    }

    /** How many times the provider's discovery document and its keys were fetched. @return array{discovery: int, keys: int} */
    public function fetches(): array
    {
        $urls = array_map(static fn ($r): string => (string) $r->getUri(), $this->http->requests);

        return [
            'discovery' => count(array_filter($urls, static fn (string $u): bool => str_ends_with($u, '/.well-known/openid-configuration'))),
            'keys' => count(array_filter($urls, static fn (string $u): bool => str_ends_with($u, '/jwks'))),
        ];
    }

    /** The token endpoint hands out this ID token. */
    public function tokenEndpointReturns(string $idToken, string $issuer = self::ISSUER): void
    {
        $this->http->routes[$issuer . '/token'] = [200, ['access_token' => 'at', 'id_token' => $idToken]];
    }

    /**
     * @param array<string, mixed> $override claims to change; a null value removes the claim
     * @param string $signedWith `main` (the provider's key) or `other` (someone else's key)
     */
    public function idToken(array $override = [], string $nonce = 'nonce-1', string $signedWith = 'main'): string
    {
        return $this->rs256(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1'], $this->claims($override, $nonce), $signedWith === 'other' ? self::otherKey() : self::key());
    }

    /** A token claiming HS256, "signed" with the provider's public key as the secret (the classic algorithm confusion attack). */
    public function confusedToken(string $nonce = 'nonce-1'): string
    {
        $publicPem = openssl_pkey_get_details(self::key())['key'];
        $input = $this->part(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'kid-1']) . '.' . $this->part($this->claims([], $nonce));

        return $input . '.' . Base64Url::encode(hash_hmac('sha256', $input, $publicPem, true));
    }

    /** A token that says it needs no signature (`alg: none`), with no signature or a made-up one. */
    public function unsignedToken(string $signature = '', string $nonce = 'nonce-1'): string
    {
        return $this->part(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'kid-1']) . '.' . $this->part($this->claims([], $nonce)) . '.' . $signature;
    }

    /** A genuinely signed token that does not say which key signed it. */
    public function tokenWithoutKeyId(string $nonce = 'nonce-1'): string
    {
        return $this->rs256(['alg' => 'RS256', 'typ' => 'JWT'], $this->claims([], $nonce), self::key());
    }

    /**
     * @param array<string, mixed> $override claims to change; a null value removes the claim
     * @return array<string, mixed>
     */
    private function claims(array $override, string $nonce): array
    {
        return array_filter($override + [
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => 'sub-42', 'nonce' => $nonce,
            'email' => 'alice@example.com', 'email_verified' => true, 'name' => 'Alice Example',
            'iat' => time(), 'exp' => time() + 600,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    private function rs256(array $header, array $claims, \OpenSSLAsymmetricKey $key): string
    {
        $input = $this->part($header) . '.' . $this->part($claims);
        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        return $input . '.' . Base64Url::encode($signature);
    }

    /** @param array<string, mixed> $data */
    private function part(array $data): string
    {
        return Base64Url::encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function key(): \OpenSSLAsymmetricKey
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private static function otherKey(): \OpenSSLAsymmetricKey
    {
        return self::$otherKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    private function privatePem(\OpenSSLAsymmetricKey $key): string
    {
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    /** @return array<string, string> */
    private function jwk(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $rsa = openssl_pkey_get_details($key)['rsa'];

        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => Base64Url::encode($rsa['n']), 'e' => Base64Url::encode($rsa['e'])];
    }
}
