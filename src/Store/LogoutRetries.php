<?php

declare(strict_types=1);

namespace PhpSso\Store;

/**
 * Back-channel logout notifications that an app did not accept, kept to be sent again later. One entry is "tell this app that this session ended";
 * the signed token is not kept (it is valid for two minutes), a fresh one is made for every attempt.
 *
 * @phpstan-type Job array{id: int, session_id: string, user_sub: string, client_id: string, attempts: int}
 */
interface LogoutRetries
{
    /** Notes that the app was not told. Does nothing when this session and app are in the queue already. */
    public function enqueue(string $sessionId, string $userSub, string $clientId, string $error, int $nextAt, ?int $now = null): void;

    /**
     * The entries whose time has come, oldest first.
     *
     * @return list<Job>
     */
    public function due(int $now, int $limit): array;

    /** Takes an entry for one attempt: it is not due again until `$leaseUntil`, so two processes do not send it twice. @return bool whether this process got it */
    public function claim(int $id, int $now, int $leaseUntil): bool;

    /** The attempt failed: another one is due at `$nextAt`. */
    public function reschedule(int $id, int $attempts, int $nextAt, string $error): void;

    /** Done: the app was told, or there is nothing left to tell. */
    public function delete(int $id): void;

    /** How many are waiting. */
    public function count(): int;
}
