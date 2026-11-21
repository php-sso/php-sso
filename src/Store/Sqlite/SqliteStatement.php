<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDOException;
use PDOStatement;
use PhpSso\Store\DuplicateValue;
use PhpSso\Store\UnknownReference;

/**
 * A statement that reports "that value is taken" as DuplicateValue, so nothing outside the SQLite stores ever sees SQLite's own
 * error. A unique or primary key violation becomes DuplicateValue, and a link to something that does not exist becomes
 * UnknownReference. Anything else (a missing required value, a broken query) is a bug and stays the error it is.
 */
final class SqliteStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw new DuplicateValue('A unique value is already taken.', 0, $e);
            }

            if (str_contains($e->getMessage(), 'FOREIGN KEY constraint failed')) {
                throw new UnknownReference('That points at something that does not exist.', 0, $e);
            }

            throw $e;
        }
    }
}
