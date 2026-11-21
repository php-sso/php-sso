<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

use PhpSso\Store\MfaFactors;
use PhpSso\Store\Settings;

/**
 * Two-step verification with an authenticator app, plus single-use recovery codes. The policy says who must have it;
 * anyone who has set it up is always asked for a code, whatever the policy says.
 */
final class Mfa
{
    public const POLICIES = ['optional', 'admins', 'everyone'];
    private const POLICY_KEY = 'mfa_policy';
    private const RECOVERY_CODES = 8;

    public function __construct(
        private MfaFactors $factors,
        private SecretBox $box,
        private Settings $settings,
        private string $issuer,
        private string $defaultPolicy = 'optional',
    ) {
    }

    /** One of POLICIES: what an administrator saved, else the config value. */
    public function policy(): string
    {
        $stored = $this->settings->get(self::POLICY_KEY);
        foreach ([$stored, $this->defaultPolicy] as $candidate) {
            if (in_array($candidate, self::POLICIES, true)) {
                return $candidate;
            }
        }

        return 'optional';
    }

    public function savePolicy(string $policy): bool
    {
        if (!in_array($policy, self::POLICIES, true)) {
            return false;
        }
        $this->settings->set(self::POLICY_KEY, $policy);

        return true;
    }

    public function isEnrolled(string $sub): bool
    {
        return $this->factors->find($sub) !== null;
    }

    /** Whether the policy makes it compulsory for this person. @param array<string, mixed> $user */
    public function requiredFor(array $user): bool
    {
        return match ($this->policy()) {
            'everyone' => true,
            'admins' => (bool) $user['is_admin'],
            default => false,
        };
    }

    /**
     * What a person who just proved their password must do next: `challenge` (give a code), `enroll` (set up the app
     * first, because the policy requires it) or `none`.
     *
     * @param array<string, mixed> $user
     */
    public function stepFor(array $user): string
    {
        if ($this->isEnrolled($user['sub'])) {
            return 'challenge';
        }

        return $this->requiredFor($user) ? 'enroll' : 'none';
    }

    public function newSecret(): string
    {
        return Totp::generateSecret();
    }

    /** @param array<string, mixed> $user */
    public function otpauthUri(array $user, string $secret): string
    {
        return Totp::uri($this->issuer, (string) $user['username'], $secret);
    }

    /** The QR code for setting the app up, as inline SVG, or null if it cannot be drawn (the key can still be typed in). @param array<string, mixed> $user */
    public function setupQr(array $user, string $secret): ?string
    {
        try {
            return QrCode::svg($this->otpauthUri($user, $secret));
        } catch (\Throwable $e) {
            error_log('SSO: could not draw the two-step QR code: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Turns the authenticator app on once a code from it proves the person set it up (replacing any earlier one).
     *
     * @param array<string, mixed> $user
     * @return string[]|null the recovery codes, to show once, or null when the code was wrong
     */
    public function enroll(array $user, string $secret, string $code, ?int $now = null): ?array
    {
        $counter = Totp::verify($secret, $code, 0, $now);
        if ($counter === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $this->factors->save($user['sub'], $this->box->seal($secret), $counter, array_map($this->hash(...), $codes));

        return $codes;
    }

    /** Accepts a code from the app (each only once) or an unused recovery code (spent by this). */
    public function verify(string $sub, string $input, ?int $now = null): bool
    {
        return $this->verifyMethod($sub, $input, $now) !== null;
    }

    /**
     * Like verify(), and says what was accepted.
     *
     * @return string|null `otp` for a code from the app, `recovery` for a recovery code, null when it was not accepted
     */
    public function verifyMethod(string $sub, string $input, ?int $now = null): ?string
    {
        $factor = $this->factors->find($sub);
        if ($factor === null) {
            return null;
        }

        if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $input) ?? '') === 1) {
            $secret = $this->box->open($factor['secret']);
            if ($secret === null) {
                error_log("SSO: the two-step secret of {$sub} cannot be decrypted (was var/keys/mfa.key replaced?)");

                return null;
            }
            $counter = Totp::verify($secret, $input, $factor['last_counter'], $now);

            return $counter !== null && $this->factors->advanceCounter($sub, $counter) ? 'otp' : null;
        }

        $normalized = $this->normalizeRecoveryCode($input);

        return preg_match('/^[A-Z2-7]{16}$/', $normalized) === 1 && $this->factors->useRecoveryCode($sub, $this->hash($normalized)) ? 'recovery' : null;
    }

    /** Replaces the recovery codes. @return string[] the new codes, to show once */
    public function regenerateRecoveryCodes(string $sub): array
    {
        $codes = $this->newRecoveryCodes();
        $this->factors->replaceRecoveryCodes($sub, array_map($this->hash(...), $codes));

        return $codes;
    }

    public function recoveryCodesLeft(string $sub): int
    {
        return $this->factors->recoveryCodesLeft($sub);
    }

    public function disable(string $sub): void
    {
        $this->factors->remove($sub);
    }

    /** @return string[] like ABCD-EFGH-IJKL-MNOP */
    private function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = implode('-', str_split(Base32::encode(random_bytes(10)), 4));
        }

        return $codes;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s-]/', '', $code) ?? '');
    }

    private function hash(string $code): string
    {
        return hash('sha256', $this->normalizeRecoveryCode($code));
    }
}
