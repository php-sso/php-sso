<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** History of sign-in attempts (successful and failed). Passwords are never stored. */
interface LoginAttempts
{
    /** @param string $factor what was checked: `password`, or `code` for the second step */
    public function record(string $username, ?string $userSub, bool $success, string $ip, string $userAgent, bool $blocked = false, bool $throttled = false, string $factor = 'password'): void;

    public function recent(int $limit): array;

    public function failedSince(int $timestamp): int;

    /**
     * When the counted failures since `$since` happened (oldest first), for an identifier (ignoring case) or for the
     * account it belongs to.
     *
     * @return int[]
     */
    public function failureTimesForIdentifier(string $identifier, ?string $userSub, int $since): array;

    /**
     * When the counted failures since `$since` from one address happened (oldest first).
     *
     * @return int[]
     */
    public function failureTimesFromIp(string $ip, int $since): array;

    /** @return list<array{username: string, user_sub: ?string, ip: string, created_at: int}> every counted failure since the time, oldest first */
    public function countedFailures(int $since): array;

    /** Whether a refusal for this address or identifier was already recorded since the time (one is enough to show). */
    public function hasThrottledSince(string $ip, string $identifier, int $since): bool;

    public function recentThrottled(int $limit): array;

    /**
     * Stops the failures of an account (and of the identifiers given, ignoring case) counting towards a limit.
     * The attempts stay in the history.
     *
     * @param string[] $identifiers
     * @return int how many failures stopped counting
     */
    public function clearForAccount(string $userSub, array $identifiers = []): int;

    /** Same, for an identifier that belongs to no account. */
    public function clearForIdentifier(string $identifier): int;

    /** Same, for the failures from one address. */
    public function clearForIp(string $ip): int;
}
