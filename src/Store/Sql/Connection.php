<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/** A database connection with the dialect that goes with it. */
final class Connection
{
    public function __construct(public readonly PDO $pdo, public readonly Dialect $dialect)
    {
    }

    /** Opens (and creates, or brings up to date) a SQLite database; `:memory:` for a throwaway one. */
    public static function sqlite(string $path): self
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $dialect = new SqliteDialect();
        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [SqlStatement::class, [$dialect]],
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new SqliteSchema($pdo))->install();

        return new self($pdo, $dialect);
    }

    public function enforceUniqueEmail(bool $unique): void
    {
        $this->dialect->enforceUniqueEmail($this->pdo, $unique);
    }
}
