<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Actions\RequiredActions;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;
use Closure;
use PhpSso\Store\UserState;

/**
 * The end of every sign-in. Whatever proved who the person is (a password now; a second factor, a social account
 * or a SAML assertion later), it finishes here, so the session rules live in one place.
 */
final class LoginFlow
{
    /** How long (seconds) a person who gave their identifier has to give the password. */
    private const PENDING_TTL = 600;

    /** How long (seconds) someone who proved their password has to give the second factor. */
    private const SECOND_FACTOR_TTL = 300;

    /** @var Closure(): mixed */
    private Closure $regenerateId;

    /** @param Closure(): mixed|null $regenerateId replaces the PHP session id; a test passes its own */
    public function __construct(
        private Sessions $sessions,
        private LoginAttempts $attempts,
        private RequiredActions $actions,
        ?Closure $regenerateId = null,
    ) {
        $this->regenerateId = $regenerateId ?? static fn (): bool => session_regenerate_id(true);
    }

    /** Remembers who is signing in, between the identifier step and the proof. `$sub` is null for an unknown identifier. */
    public function begin(string $identifier, ?string $sub): void
    {
        $_SESSION['login'] = ['stage' => 'identified', 'identifier' => $identifier, 'sub' => $sub, 'expires' => time() + self::PENDING_TTL];
    }

    /** @return array{identifier: string, sub: ?string}|null who is signing in, or null when no one is or the time is up */
    public function pending(): ?array
    {
        $login = $_SESSION['login'] ?? null;
        if (!is_array($login) || !is_string($login['identifier'] ?? null) || (int) ($login['expires'] ?? 0) < time()) {
            unset($_SESSION['login']);

            return null;
        }
        if (($login['stage'] ?? 'identified') !== 'identified') {
            return null;
        }

        return ['identifier' => $login['identifier'], 'sub' => is_string($login['sub'] ?? null) ? $login['sub'] : null];
    }

    /**
     * Whatever proved who the person is (a password, a provider account) ends here: they are signed in, unless they
     * still have to pass a second factor.
     *
     * @param array<string, mixed> $user the user row
     * @param string $step what Mfa::stepFor() said: `none`, `challenge` or `enroll`
     * @param list<string> $methods how they proved who they are, as RFC 8176 `amr` names: `pwd` for a password, `fed` for an outside account (social, SAML)
     * @return string where to send the person next
     */
    public function primaryAuthenticated(array $user, string $identifier, string $step, string $ip, string $userAgent, array $methods = ['pwd']): string
    {
        if ($step === 'none') {
            return $this->proceed($user, $identifier, $ip, $userAgent, $methods);
        }
        $this->beginSecondFactor($user['sub'], $identifier, $step, $methods);

        return $step === 'challenge' ? '/login/mfa' : '/login/mfa/setup';
    }

    /**
     * The last stop before a session: signs the person in, unless something is still required of them (a new password,
     * say). Every sign-in path ends here, after the second factor when there is one.
     *
     * @param array<string, mixed> $user the user row
     * @param list<string> $methods more ways they proved who they are, added to the ones already known from earlier steps
     * @return string where to send the person next
     */
    public function proceed(array $user, string $identifier, string $ip, string $userAgent, array $methods = []): string
    {
        $methods = $this->combine($this->pendingMethods(), $methods);
        if ($this->actions->next($user) === null) {
            return $this->complete($user, $identifier, $ip, $userAgent, $methods);
        }
        $this->beginActions($user['sub'], $identifier, $methods);

        return '/login/required';
    }

    /**
     * The person is who they say, but must finish what is required of them before they are signed in.
     *
     * @param list<string> $methods how they proved who they are so far, kept for the session that follows
     */
    public function beginActions(string $sub, string $identifier, array $methods = []): void
    {
        $_SESSION['login'] = ['stage' => 'actions', 'identifier' => $identifier, 'sub' => $sub, 'amr' => $this->combine([], $methods), 'expires' => time() + self::PENDING_TTL];
    }

    /** @return array{identifier: string, sub: string, amr: list<string>}|null who has to finish something before being signed in, or null when no one does or the time is up */
    public function actionsPending(): ?array
    {
        $login = $_SESSION['login'] ?? null;
        if (!is_array($login) || ($login['stage'] ?? null) !== 'actions') {
            return null;
        }
        if ((int) ($login['expires'] ?? 0) < time() || !is_string($login['sub'] ?? null) || !is_string($login['identifier'] ?? null)) {
            unset($_SESSION['login']);

            return null;
        }

        return ['identifier' => $login['identifier'], 'sub' => $login['sub'], 'amr' => $this->combine([], (array) ($login['amr'] ?? []))];
    }

    /**
     * The person proved who they are (with a password, for now) but is not signed in until they have passed the second
     * factor. `$step` is `challenge` (give a code) or `enroll` (set up the authenticator app first).
     *
     * @param list<string>|null $methods how they proved who they are so far; null keeps what is known already
     */
    public function beginSecondFactor(string $sub, string $identifier, string $step, ?array $methods = null): void
    {
        $known = $methods ?? $this->pendingMethods();
        $_SESSION['login'] = ['stage' => 'second_factor', 'step' => $step, 'identifier' => $identifier, 'sub' => $sub, 'passed' => false, 'amr' => $this->combine([], $known), 'expires' => time() + self::SECOND_FACTOR_TTL];
    }

    /** @return array{identifier: string, sub: string, step: string, passed: bool}|null who still has to pass the second factor, or null when no one does or the time is up */
    public function secondFactorPending(): ?array
    {
        $login = $_SESSION['login'] ?? null;
        if (!is_array($login) || ($login['stage'] ?? null) !== 'second_factor') {
            return null;
        }
        if ((int) ($login['expires'] ?? 0) < time() || !is_string($login['sub'] ?? null) || !is_string($login['identifier'] ?? null)) {
            unset($_SESSION['login']);

            return null;
        }

        return ['identifier' => $login['identifier'], 'sub' => $login['sub'], 'step' => (string) ($login['step'] ?? 'challenge'), 'passed' => (bool) ($login['passed'] ?? false)];
    }

    /**
     * The second factor was passed; the person may still be reading their recovery codes, so the time is extended.
     *
     * @param list<string> $methods what that factor adds to how they signed in (`otp`, `mfa`)
     */
    public function markSecondFactorPassed(array $methods = []): void
    {
        if (isset($_SESSION['login']) && is_array($_SESSION['login'])) {
            $_SESSION['login']['passed'] = true;
            $_SESSION['login']['amr'] = $this->combine($this->pendingMethods(), $methods);
            $_SESSION['login']['expires'] = time() + self::PENDING_TTL;
        }
    }

    /** How the person has proved who they are so far in this sign-in. @return list<string> */
    private function pendingMethods(): array
    {
        $login = $_SESSION['login'] ?? null;

        return $this->combine([], is_array($login) ? (array) ($login['amr'] ?? []) : []);
    }

    /**
     * Two lists of method names as one: only usable names, each once, in order.
     *
     * @param array<mixed> $first
     * @param array<mixed> $more
     * @return list<string>
     */
    private function combine(array $first, array $more): array
    {
        $all = [];
        foreach ([...$first, ...$more] as $method) {
            if (is_string($method) && $method !== '' && !in_array($method, $all, true)) {
                $all[] = $method;
            }
        }

        return $all;
    }

    /**
     * Signs the person in: ends the session they replace, starts a new one and records the attempt.
     *
     * @param array<string, mixed> $user the user row
     * @param string $identifier what the person typed, kept in the sign-in history
     * @param list<string>|null $amr how they proved who they are, kept with the session for the ID tokens of apps
     * @return string where to send the person next
     */
    public function complete(array $user, string $identifier, string $ip, string $userAgent, ?array $amr = null): string
    {
        if (UserState::isLocked($user)) { // locked between the password and the end of the sign-in
            unset($_SESSION['login']);

            return '/login';
        }
        $returnTo = $_SESSION['return_to'] ?? '/';
        // Signing in on top of an existing session: the same person re-authenticating (prompt=login, max_age) just
        // replaces it; someone else is a real change of person, so their old session ends properly.
        $previous = $_SESSION['sid'] ?? null;
        if (is_string($previous)) {
            ($_SESSION['user'] ?? null) === $user['sub'] ? $this->sessions->markEnded($previous) : $this->sessions->end($previous);
        }
        ($this->regenerateId)(); // prevent session fixation
        $_SESSION = ['user' => $user['sub'], 'sid' => $this->sessions->start($user['sub'], $ip, $userAgent, null, $amr)];
        $this->attempts->record($identifier, $user['sub'], true, $ip, $userAgent);

        return is_string($returnTo) ? $returnTo : '/';
    }
}
