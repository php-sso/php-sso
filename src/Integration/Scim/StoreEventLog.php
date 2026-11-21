<?php

declare(strict_types=1);

namespace PhpSso\Integration\Scim;

use PhpSso\ScimServer\Contract\EventLog;
use PhpSso\Store\ScimEvents;

/** Notes what provisioning clients asked for in the `scim_events` table, where the admin panel shows it. */
final class StoreEventLog implements EventLog
{
    public function __construct(private ScimEvents $events)
    {
    }

    public function record(int $clientId, string $method, string $path, int $status, string $detail): void
    {
        $this->events->record($clientId, $method, $path, $status, $detail);
    }
}
