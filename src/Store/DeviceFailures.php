<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** The wrong codes people typed on the device approval page, which are limited per person. */
interface DeviceFailures
{
    /** How many wrong codes this person typed since the given time. */
    public function count(string $userSub, int $since): int;

    public function record(string $userSub, int $at): void;
}
