<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PDO;

/** Settings an administrator changes in the admin panel. Each has a text value; JSON is fine for structured ones. */
final class SqlSettings implements \PhpSso\Store\Settings
{
    private PDO $pdo;
    private Dialect $dialect;

    public function __construct(Connection $db)
    {
        $this->pdo = $db->pdo;
        $this->dialect = $db->dialect;
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT "value" FROM settings WHERE "key" = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->prepare($this->dialect->upsert('settings', ['"key"', '"value"'], ['"key"']))
            ->execute([$key, $value]);
    }
}
