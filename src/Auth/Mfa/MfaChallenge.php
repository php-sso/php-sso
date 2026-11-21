<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

use PhpSso\Auth\LoginThrottle;
use PhpSso\Store\LoginAttempts;

/**
 * Checks a code a person types, under the same limits as passwords: a wrong code is a failed attempt for their
 * account, and past the limit nothing is checked until old failures leave the window. Codes are short, so this matters.
 */
final class MfaChallenge
{
    public function __construct(private Mfa $mfa, private LoginThrottle $throttle, private LoginAttempts $attempts)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param string $identifier what the person typed to sign in, kept in the history
     */
    public function attempt(array $user, string $identifier, string $input, string $ip, string $userAgent): ChallengeResult
    {
        $wait = $this->throttle->lockedFor($identifier, $user['sub'], $ip);
        if ($wait !== null) {
            $this->throttle->recordLocked($identifier, $user['sub'], $ip, $userAgent);

            return new ChallengeResult(false, $wait);
        }
        $method = $this->mfa->verifyMethod($user['sub'], $input);
        if ($method !== null) {
            return new ChallengeResult(true, null, $method);
        }
        $this->attempts->record($identifier, $user['sub'], false, $ip, $userAgent, factor: 'code');

        return new ChallengeResult(false);
    }
}
