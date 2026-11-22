<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use Closure;
use DomainException;
use PhpSso\Audit\Audit;
use PhpSso\Mail\Emails;
use PhpSso\Mail\MailFailed;
use PhpSso\Mail\Mailer;
use PhpSso\Store\PasswordResets;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;
use PhpSso\Store\UserState;

/**
 * Choosing a new password through a link sent by email.
 *
 * - The answer to "I forgot my password" is the same whether or not an account matches, and the mail is sent after the answer, so neither the words nor
 *   the time they took say whether someone has an account.
 * - A link holds a random token (43 characters, 256 bits). Only its SHA-256 is stored, it works once, it expires, and a newer link replaces the older one.
 * - Requests are limited per address and per account, within an hour; what is over the limit is answered like the rest and not sent.
 * - An account that is locked, has no local password (it signs in elsewhere) or has no usable address is never sent a link.
 * - A password that was set this way ends every session of the person, clears their failed sign-ins, and a notice is sent to the same address.
 *   Two-step verification stays: the link proves the mailbox, not the person's phone.
 */
final class PasswordReset
{
    public const MIN_LENGTH = 8;
    public const REQUESTS_PER_ADDRESS = 10;
    public const REQUESTS_PER_ACCOUNT = 3;
    private const WINDOW_SECONDS = 3600;

    /**
     * @param Closure(Closure(): void): void $defer runs what it is given after the answer has been sent (at once, in a test)
     * @param Closure(string, string...): mixed $clearFailures forgets the failed sign-ins of an account: its id, then the names it signs in with
     */
    public function __construct(
        private Users $users,
        private PasswordResets $resets,
        private Sessions $sessions,
        private Closure $clearFailures,
        private Mailer $mailer,
        private Emails $emails,
        private ?Audit $audit,
        private string $issuer,
        private int $minutes,
        private Closure $defer,
    ) {
    }

    /** Someone says they forgot their password. Never says anything about the account. */
    public function request(string $typed, string $ip, ?int $now = null): void
    {
        $now ??= time();
        if ($this->resets->requestsFrom($ip, $now - self::WINDOW_SECONDS) >= self::REQUESTS_PER_ADDRESS) {
            return;
        }

        $typed = trim(mb_substr($typed, 0, 255));
        $user = $typed === '' ? null : ($this->users->findByUsername($typed) ?? $this->users->findByEmail($typed));
        $recent = $this->resets->requestsFor((string) ($user['sub'] ?? ''), $now - self::WINDOW_SECONDS); // asked of everyone, so every request does the same work
        if ($user === null || $this->refusal($user) !== null || $recent >= self::REQUESTS_PER_ACCOUNT) {
            $this->resets->record($ip, $now);

            return;
        }

        $link = $this->link($this->issue($user, $ip, $now));
        $this->afterTheAnswer(fn () => $this->emails->passwordReset($user, $link, $this->minutes, $ip), 'a password reset email');
    }

    /**
     * An administrator sends a person the link. Unlike {@see request()} this says what happened.
     *
     * @param array<string, mixed> $user
     * @throws DomainException when this person cannot be sent one (the reason is for the administrator)
     * @throws MailFailed when the message could not be sent
     */
    public function sendTo(array $user, string $ip, ?int $now = null): void
    {
        if (($reason = $this->refusal($user)) !== null) {
            throw new DomainException($reason);
        }
        $this->mailer->send($this->emails->passwordReset($user, $this->link($this->issue($user, $ip, $now ?? time())), $this->minutes, $ip, byAdmin: true));
    }

    /**
     * The person a link is for, when the link works.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(string $token, ?int $now = null): ?array
    {
        $entry = $this->resets->find(self::hash($token), $now);
        $user = $entry === null ? null : $this->users->find($entry['user_sub']);

        return $user !== null && $this->refusal($user) === null ? $user : null;
    }

    /** @return string|null what is wrong (for the person to read), or null when the password was changed */
    public function complete(string $token, string $password, string $confirmation, string $ip, ?int $now = null): ?string
    {
        $now ??= time();
        $user = $this->inspect($token, $now);
        if ($user === null) {
            return 'This link is no longer valid.';
        }
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (!hash_equals($password, $confirmation)) {
            return 'The two passwords do not match.';
        }

        $entry = $this->resets->find(self::hash($token), $now);
        if ($entry === null || !$this->resets->consume($entry['id'], $now)) {
            return 'This link is no longer valid.'; // someone used it between the check and now
        }

        $this->users->setPassword($user['sub'], $password); // also clears "must choose a new password"
        $this->sessions->endAllForUser($user['sub']);
        ($this->clearFailures)($user['sub'], (string) $user['username'], (string) $user['email']);
        $this->resets->revokeFor($user['sub']);
        $this->audit?->record('account.password_reset', $user, $ip, 'user', (string) $user['sub'], (string) $user['username']);

        $this->afterTheAnswer(fn () => $this->emails->passwordChanged($user, $ip, $now), 'the password changed notice');

        return null;
    }

    /**
     * Makes the message and sends it once the answer has gone: a person who asks does not wait for the mail server, and neither the time nor the words
     * say whether an account matched. A failure is logged, not shown.
     *
     * @param Closure(): \PhpSso\Mail\Email $make
     */
    private function afterTheAnswer(Closure $make, string $what): void
    {
        ($this->defer)(function () use ($make, $what): void {
            try {
                $this->mailer->send($make());
            } catch (\Throwable $e) {
                error_log("SSO: could not send {$what}: " . $e->getMessage());
            }
        });
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Why this person is not sent a link, or null when they may be. @param array<string, mixed> $user */
    public function refusal(array $user): ?string
    {
        return match (true) {
            UserState::isLocked($user) => 'The account is locked: unlock it first.',
            !is_string($user['password_hash'] ?? null) => 'The account has no password: the person signs in another way.',
            filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL) === false => 'The account has no usable email address.',
            default => null,
        };
    }

    /** @param array<string, mixed> $user */
    private function issue(array $user, string $ip, int $now): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->resets->issue((string) $user['sub'], self::hash($token), $now + $this->minutes * 60, $ip, $now);

        return $token;
    }

    private function link(string $token): string
    {
        return rtrim($this->issuer, '/') . '/password/reset/' . $token;
    }
}
