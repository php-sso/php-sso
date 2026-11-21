<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use PhpSso\Store\Identities;
use PhpSso\Store\Users;

/**
 * Who an outside account (social or SAML) is: someone it was connected to before, else, if allowed, the person with the
 * same verified email address, else, if allowed, a new person. The outside account's own id for the person is what counts,
 * never the email address alone.
 */
final class AccountMatcher
{
    public function __construct(private Identities $identities, private Users $users)
    {
    }

    /**
     * @param string $label how the provider is called to people
     * @param bool $linkByEmail connect to the person with the same verified address
     * @param bool $createUsers make a new person when nobody matches
     * @param bool $domainAllowed whether the address may be used for either (the allowed-domains rule)
     * @return array<string, mixed> the user row
     * @throws SocialLoginException
     */
    public function match(ExternalIdentity $identity, string $label, bool $linkByEmail, bool $createUsers, bool $domainAllowed): array
    {
        $known = $this->identities->find($identity->provider, $identity->subject);
        if ($known !== null) {
            $user = $this->users->find($known['user_sub']);
            if ($user !== null) {
                $this->identities->touch((int) $known['id'], $identity->email);

                return $user;
            }
        }

        $email = $identity->verifiedEmail();
        $allowed = $email !== null && $domainAllowed;

        if ($allowed && $linkByEmail) {
            $match = $this->users->findByEmail($email);
            // Only a person whose address we verified too, and who has no other account at this provider (a different
            // one would mean the address changed hands).
            if ($match !== null && (bool) $match['email_verified'] && !$this->hasProvider($match['sub'], $identity->provider)) {
                $this->connect($match, $identity);

                return $match;
            }
        }

        if ($allowed && $createUsers && !$this->users->emailTaken($email)) {
            return $this->createUser($identity, $email);
        }

        throw new SocialLoginException(
            "No account here is connected to that {$label} account. Sign in another way, then connect it on your account page, or ask an administrator.",
            "Unmatched {$identity->provider} identity {$identity->subject} (email " . ($email ?? 'none or unverified') . ').',
        );
    }

    /** Connects the outside account to the person (or notes the sign-in when it already is). @param array<string, mixed> $user @throws SocialLoginException */
    public function connect(array $user, ExternalIdentity $identity): void
    {
        $existing = $this->identities->find($identity->provider, $identity->subject);
        if ($existing === null) {
            $this->identities->link($user['sub'], $identity->provider, $identity->subject, $identity->email);

            return;
        }
        if ($existing['user_sub'] !== $user['sub']) {
            throw new SocialLoginException('That account is already connected to someone else.', "Identity {$identity->provider}:{$identity->subject} belongs to another user.");
        }
        $this->identities->touch((int) $existing['id'], $identity->email);
    }

    private function hasProvider(string $sub, string $provider): bool
    {
        return in_array($provider, array_column($this->identities->forUser($sub), 'provider'), true);
    }

    /** @return array<string, mixed> */
    private function createUser(ExternalIdentity $identity, string $email): array
    {
        $base = substr(preg_replace('/[^a-z0-9._-]/', '', strtolower((string) strstr($email, '@', true))) ?? '', 0, 50);
        $base = strlen($base) < 2 ? 'user' : $base;
        $username = $base;
        for ($i = 2; $this->users->findByUsername($username) !== null; $i++) {
            $username = $base . $i;
        }

        $sub = $this->users->create([
            'username' => $username,
            'password' => null,
            'name' => $identity->name !== null && $identity->name !== '' ? mb_substr($identity->name, 0, 100) : $username,
            'email' => $email,
            'email_verified' => true,
            'is_admin' => false,
        ]);
        $this->identities->link($sub, $identity->provider, $identity->subject, $identity->email);

        return $this->users->find($sub) ?? throw new SocialLoginException('We could not create your account.', 'The new user vanished.');
    }
}
