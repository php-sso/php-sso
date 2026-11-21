<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** The owner/item links managed through the admin relation picker, by name. */
interface Relations
{
    public function get(string $name): ?\PhpSso\Store\Relation;
}
