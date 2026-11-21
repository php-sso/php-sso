<?php

declare(strict_types=1);

namespace PhpSso\Store;

use RuntimeException;

/** A store refused a value because another record already has it (a unique name or identifier), however the store is built. */
final class DuplicateValue extends RuntimeException
{
}
