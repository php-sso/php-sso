<?php

declare(strict_types=1);

namespace PhpSso\Store;

use RuntimeException;

/** A store refused a record because it points at something that does not exist (a client, a group, a person), however the store is built. */
final class UnknownReference extends RuntimeException
{
}
