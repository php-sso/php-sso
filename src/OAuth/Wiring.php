<?php

declare(strict_types=1);

namespace PhpSso\OAuth;

use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use PhpSso\Audit\Audit;
use PhpSso\Keys;
use PhpSso\Net\StreamHttpClient;
use PhpSso\OAuthServer\Clock\SystemClock;
use PhpSso\OAuthServer\Config\ServerConfig;
use PhpSso\OAuthServer\Jwt\JwtEncoder;
use PhpSso\OAuthServer\Logout\BackchannelLogout;
use PhpSso\OAuthServer\Token\RandomTokenGenerator;
use PhpSso\Store\Storage;

/** Pieces of the OAuth server's setup that more than one entry point (the web front controller, `bin/maintenance`) needs. */
final class Wiring
{
    /** @param array<string, mixed> $config the app's config */
    public static function serverConfig(array $config, Storage $storage): ServerConfig
    {
        return new ServerConfig(
            issuer: $config['issuer'],
            scopes: $storage->scopes()->names(),
            authorizationCodeTtl: $config['auth_code_ttl'],
            accessTokenTtl: $config['access_token_ttl'],
            idTokenTtl: $config['access_token_ttl'],
            refreshTokenTtl: $config['refresh_token_ttl'],
            requirePkceForConfidentialClients: $config['require_pkce_for_confidential_clients'],
        );
    }

    /** The retrier for back-channel logout notifications that failed: it sends with the library's own sender, and a fresh token each time. */
    public static function logoutRetrier(array $config, Storage $storage, Keys $keys, ?Audit $audit): LogoutRetrier
    {
        $serverConfig = self::serverConfig($config, $storage);
        $clients = new RegisteredClients($storage->clients(), $storage->scopes()->builtinNames());
        $http = new StreamHttpClient();

        return new LogoutRetrier($storage->logoutRetries(), static fn (string $clientId, string $sessionId, string $userSub): array => (new BackchannelLogout(
            $serverConfig,
            $clients,
            new OneClientRegistry($clientId),
            new JwtEncoder($keys->provider),
            new RandomTokenGenerator(),
            new SystemClock(),
            $http,
            new RequestFactory(),
            new StreamFactory(),
        ))->notify($sessionId, $userSub), $audit);
    }
}
