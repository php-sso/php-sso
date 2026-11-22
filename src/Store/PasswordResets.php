<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * The tokens that let someone choose a new password, and the requests for them. Only the SHA-256 of a token is kept, so a copy of the database
 * does not hold a working link. A token is used once and expires; a person has one working token at a time. A request that made no token
 * (nobody matched) is noted too, so the limits count what an attacker tries and not only what succeeds.
 */
interface PasswordResets
{
    /** Notes a request that made no token. */
    public function record(string $ip, ?int $now = null): void;

    /** Makes a token for a person: an earlier one of theirs that was not used stops working. */
    public function issue(string $userSub, string $tokenHash, int $expiresAt, string $ip, ?int $now = null): void;

    /** How many requests came from this address since the time. */
    public function requestsFrom(string $ip, int $since): int;

    /** How many requests made a token for this person since the time. */
    public function requestsFor(string $userSub, int $since): int;

    /**
     * The token's entry when it exists, has not been used and has not expired.
     *
     * @return array{id: int, user_sub: string}|null
     */
    public function find(string $tokenHash, ?int $now = null): ?array;

    /** Uses the token up. @return bool whether this call did it (false: it was used, or had expired, meanwhile) */
    public function consume(int $id, ?int $now = null): bool;

    /** Makes every token of the person that was not used stop working. */
    public function revokeFor(string $userSub): void;

    /** @return int how many entries were removed */
    public function deleteOlderThan(int $time): int;
}
