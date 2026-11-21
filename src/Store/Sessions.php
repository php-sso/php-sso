<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * SSO browser sessions. The PHP session holds only the id of a row here, so an administrator
 * can end a session and the next request from that browser is signed out.
 */
interface Sessions
{
    /**
     * @param string|null $impersonatedBy the administrator who is browsing as this person, for the audit trail
     * @param list<string>|null $amr how the person signed in (RFC 8176 names such as `pwd`, `otp`, `mfa`), for the ID token's `amr`
     */
    public function start(string $userSub, string $ip, string $userAgent, ?string $impersonatedBy = null, ?array $amr = null): string;

    /** True (and refreshes the idle timer) when the session exists, belongs to the user, and is still live. */
    public function touch(string $id, string $userSub): bool;

    /** @param callable(array<int, array{id: string, user_sub: string}>): void $listener */
    public function onEnded(callable $listener): void;

    /** Ends a session. @return int how many were ended (0 or 1) */
    public function end(string $id): int;

    /** Ends one session, but only if it belongs to the user. */
    public function endForUser(string $id, string $userSub): int;

    /** Ends every live session of the user except the given one. */
    public function endOthers(string $userSub, string $keepId): int;

    public function endAllForUser(string $userSub): int;

    /**
     * Marks a session ended without telling anyone: for the end-session endpoint, which revokes and notifies
     * through the OAuth server itself, and for a session replaced by a fresh login.
     */
    public function markEnded(string $id): void;

    /** When the person signed in during this session: it is their `auth_time`. Null for an unknown session. */
    public function authTime(string $id): ?int;

    /** How the person signed in during this session (`pwd`, `otp`, `mfa`, ...), or null when it is not known (older sessions, impersonation). @return list<string>|null */
    public function amr(string $id): ?array;

    public function addClient(string $sessionId, string $clientId): void;

    /** @return string[] clients that were sent a code during this session */
    public function clientIds(string $sessionId): array;

    /** Live sessions, most recently active first, with the user's name. */
    public function active(): array;

    /** Drops rows that ended or expired more than a week ago. */
    public function prune(): void;
}
