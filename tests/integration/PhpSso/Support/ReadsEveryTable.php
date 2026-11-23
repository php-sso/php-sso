<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Support;

use PhpSso\Backup\BackupTables;
use PhpSso\Store\Sql\Connection;

/** Reads every table that a backup holds, to compare two databases. */
trait ReadsEveryTable
{
    /** @return array<string, list<array<string, mixed>>> every table of the backup, with its rows in a fixed order */
    private function everything(Connection $db): array
    {
        $result = [];
        foreach (BackupTables::ORDER as $table) {
            $rows = $db->pdo->query('SELECT * FROM "' . $table . '"')->fetchAll();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $result[$table] = $rows;
        }

        return $result;
    }
}
