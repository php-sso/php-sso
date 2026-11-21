<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use PhpSso\Store\HttpCache;
use InvalidArgumentException;

/**
 * The providers people can sign in with. A provider in the config without a client id and secret is listed for
 * administrators but not offered to anyone.
 */
final class SocialProviders
{
    /**
     * @param array<string, SocialProvider> $enabled
     * @param list<array{key: string, label: string, enabled: bool}> $all
     */
    public function __construct(private array $enabled = [], private array $all = [])
    {
    }

    /**
     * @param array<string, array<string, mixed>> $config provider key => settings (`type` oidc or github, `client_id`,
     *        `client_secret`, `label`, and for oidc `issuer`, `scopes`, `allow_http`)
     * @param HttpCache|null $cache where an OpenID provider's discovery document and keys are kept between sign-ins
     */
    public static function fromConfig(array $config, HttpJson $http, ?HttpCache $cache = null): self
    {
        $enabled = [];
        $all = [];
        foreach ($config as $key => $settings) {
            if (preg_match('/^[a-z0-9_-]{1,32}$/', (string) $key) !== 1) {
                throw new InvalidArgumentException("A social provider key may only hold a-z, 0-9, _ and -: {$key}");
            }
            $label = (string) ($settings['label'] ?? ucfirst((string) $key));
            $type = (string) ($settings['type'] ?? 'oidc');
            $id = (string) ($settings['client_id'] ?? '');
            $secret = (string) ($settings['client_secret'] ?? '');
            $ready = $id !== '' && $secret !== '' && ($type !== 'oidc' || (string) ($settings['issuer'] ?? '') !== '');

            $provider = null;
            if ($ready) {
                $provider = match ($type) {
                    'github' => new GithubProvider($id, $secret, $http, (string) ($settings['web_base'] ?? 'https://github.com'), (string) ($settings['api_base'] ?? 'https://api.github.com')),
                    'oidc' => new OidcProvider((string) $key, $label, (string) $settings['issuer'], $id, $secret, $http, $settings['scopes'] ?? ['openid', 'email', 'profile'], (bool) ($settings['allow_http'] ?? false), $cache),
                    default => throw new InvalidArgumentException("Unknown social provider type \"{$type}\" for {$key}. Use oidc or github."),
                };
                $enabled[(string) $key] = $provider;
            }
            $all[] = ['key' => (string) $key, 'label' => $provider?->label() ?? $label, 'enabled' => $provider !== null];
        }

        return new self($enabled, $all);
    }

    public function get(string $key): ?SocialProvider
    {
        return $this->enabled[$key] ?? null;
    }

    /** @return array<string, SocialProvider> */
    public function enabled(): array
    {
        return $this->enabled;
    }

    /** @return list<array{key: string, label: string}> what the sign-in page offers */
    public function buttons(): array
    {
        return array_values(array_map(static fn (SocialProvider $p): array => ['key' => $p->key(), 'label' => $p->label()], $this->enabled));
    }

    /** @return list<array{key: string, label: string, enabled: bool}> everything in the config */
    public function all(): array
    {
        return $this->all;
    }

    /** The name to show for a provider id, including one that was removed from the config since people linked it. */
    public function labelFor(string $key): string
    {
        foreach ($this->all as $entry) {
            if ($entry['key'] === $key) {
                return $entry['label'];
            }
        }

        return ucfirst($key);
    }
}
