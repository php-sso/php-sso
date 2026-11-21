<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

/** The outcome of asking for a second-factor code: accepted, wrong, or refused because of too many failures. */
final class ChallengeResult
{
    /**
     * @param int|null $retryAfter seconds until another try is allowed, when the person is locked out
     * @param string|null $method what was accepted: `otp` (a code from the app) or `recovery` (a recovery code)
     */
    public function __construct(public readonly bool $accepted, public readonly ?int $retryAfter = null, public readonly ?string $method = null)
    {
    }

    /**
     * What this adds to how the person signed in, as RFC 8176 `amr` names: a code from the app is a one-time password and a
     * second factor (`otp`, `mfa`); a recovery code is a second factor only (`mfa`).
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return match (true) {
            !$this->accepted => [],
            $this->method === 'otp' => ['otp', 'mfa'],
            default => ['mfa'],
        };
    }

    public function lockedOut(): bool
    {
        return $this->retryAfter !== null;
    }

    /** What to tell the person who did not get in. */
    public function message(): string
    {
        if ($this->retryAfter === null) {
            return 'That code is not right.';
        }

        return 'Too many failed attempts. Try again in ' . ($this->retryAfter < 90 ? 'a minute' : (int) ceil($this->retryAfter / 60) . ' minutes') . '.';
    }
}
