<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Store\Settings;

/**
 * What may happen automatically when someone signs in with an outside account (social, SAML) that is not connected to anyone
 * yet: connecting it to the person with the same verified email, or making a new account. The social switches are saved here
 * and are off until an administrator allows them, because both trust what the provider says about an email address. The
 * allowed email domains apply to every automatic path, not to people an administrator creates.
 */
final class AccountPolicy
{
    /** Still called social_policy in the settings table, from when it only governed social sign-in: renaming it would lose what was saved. */
    private const KEY = 'social_policy';

    /** @param array<string, mixed> $defaults the config values, used until something is saved */
    public function __construct(private Settings $settings, private array $defaults = [])
    {
    }

    /** @return array{link_by_verified_email: bool, create_users: bool, allowed_domains: list<string>} */
    public function current(): array
    {
        $stored = json_decode((string) $this->settings->get(self::KEY), true);
        $merged = (is_array($stored) ? $stored : []) + $this->defaults + ['link_by_verified_email' => false, 'create_users' => false, 'allowed_domains' => []];

        return [
            'link_by_verified_email' => (bool) $merged['link_by_verified_email'],
            'create_users' => (bool) $merged['create_users'],
            'allowed_domains' => self::domainsFrom(implode("\n", (array) $merged['allowed_domains'])),
        ];
    }

    /** The settings as the form shows them (domains one per line). @return array{link_by_verified_email: bool, create_users: bool, allowed_domains: string} */
    public function form(): array
    {
        $current = $this->current();

        return ['link_by_verified_email' => $current['link_by_verified_email'], 'create_users' => $current['create_users'], 'allowed_domains' => implode("\n", $current['allowed_domains'])];
    }

    /**
     * Reads the form. The domains come back as typed, so the form can show them again.
     *
     * @param array<mixed> $body
     * @return array{0: array{link_by_verified_email: bool, create_users: bool, allowed_domains: string}, 1: array<string, string>}
     */
    public static function parseForm(array $body): array
    {
        $text = (string) ($body['allowed_domains'] ?? '');
        $errors = [];
        foreach (preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $domain) {
            if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain) !== 1) {
                $errors['allowed_domains'] = "\"{$domain}\" is not a domain name. Use one per line, such as example.com.";
                break;
            }
        }

        return [['link_by_verified_email' => isset($body['link_by_verified_email']), 'create_users' => isset($body['create_users']), 'allowed_domains' => $text], $errors];
    }

    /** @param array{link_by_verified_email: bool, create_users: bool, allowed_domains: string} $form values that passed parseForm() */
    public function save(array $form): void
    {
        $this->settings->set(self::KEY, json_encode([
            'link_by_verified_email' => $form['link_by_verified_email'],
            'create_users' => $form['create_users'],
            'allowed_domains' => self::domainsFrom($form['allowed_domains']),
        ], JSON_THROW_ON_ERROR));
    }

    /** Whether an email address is in an allowed domain (every domain is allowed when the list is empty). */
    public function domainAllowed(string $email): bool
    {
        $domains = $this->current()['allowed_domains'];
        $at = strrpos($email, '@');

        return $domains === [] || ($at !== false && in_array(strtolower(substr($email, $at + 1)), $domains, true));
    }

    /** @return list<string> lower-case, without duplicates */
    public static function domainsFrom(string $text): array
    {
        return array_values(array_unique(array_map('strtolower', preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])));
    }
}
