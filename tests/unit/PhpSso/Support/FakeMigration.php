<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use Closure;
use PhpSso\Store\Sql\Connection;
use PhpSso\Store\Sql\Migrations\Migration;

/** A migration made on the spot, for testing the Migrator without changing the app's real list. */
final class FakeMigration implements Migration
{
    /** @param Closure(Connection): void $body */
    public function __construct(private string $id, private Closure $body, private string $description = 'A test migration')
    {
    }

    /** One that changes nothing. */
    public static function noop(string $id): self
    {
        return new self($id, static function (): void {
        });
    }

    /** One that creates a table (when it is not there yet: safe to run again). */
    public static function createsTable(string $id, string $table): self
    {
        return new self($id, static function (Connection $db) use ($table): void {
            if (!$db->tableExists($table)) {
                $db->pdo->exec('CREATE TABLE "' . $table . '" (id INTEGER NOT NULL PRIMARY KEY)');
            }
        });
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function run(Connection $db): void
    {
        ($this->body)($db);
    }
}
