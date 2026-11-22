<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql\Migrations;

use PhpSso\Store\Sql\Connection;

/**
 * One step that takes the database from one schema to the next. A migration never changes after it has shipped: the next
 * change is a new migration. See {@see SqlMigration} for the base class to extend, and {@see Migrations} for the list.
 */
interface Migration
{
    /** A zero padded number, such as `0001`: migrations run in the order of their ids, and an id is recorded once it ran. */
    public function id(): string;

    /** A line for `bin/migrate status`: what the migration does. */
    public function description(): string;

    /**
     * Makes the change, for whichever database `$db` is. The migrator opens a transaction around it where the database can
     * roll back DDL (SQLite, PostgreSQL), so a failure leaves nothing behind. On MySQL it cannot: the steps must be safe to
     * run again after a failure (see {@see Connection::tableExists()} and friends).
     */
    public function run(Connection $db): void;
}
