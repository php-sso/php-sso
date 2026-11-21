<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** The failed attempts to authenticate as a client (a wrong secret), which the throttle counts. */
interface ClientAuthFailures
{
    /**
     * When this address failed since the given time, oldest first. With a client id, only failures that named that client count.
     *
     * @return list<int>
     */
    public function times(string $ip, ?string $clientId, int $since): array;

    /** Notes a failure, and forgets the ones at or before `$keepAfter`. */
    public function record(?string $clientId, string $ip, int $at, int $keepAfter): void;
}
