<?php

declare(strict_types=1);

namespace PhpSso\Store\Sqlite;

use PDO;

/** Settings an administrator changes in the admin panel. Each has a text value; JSON is fine for structured ones. */
final class SqliteSettings implements \PhpSso\Store\Settings
{
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->pdo = $db->pdo;
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }
}
