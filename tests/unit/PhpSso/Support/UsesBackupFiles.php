<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Backup\Exporter;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PhpSso\Store\Sql\Connection;

/** A folder for the archives and key files of a backup test, removed afterwards. */
trait UsesBackupFiles
{
    private string $work;

    #[Before]
    protected function makeBackupFolder(): void
    {
        $this->work = sys_get_temp_dir() . '/php-sso-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->work, 0700, true);
    }

    #[After]
    protected function removeBackupFolder(): void
    {
        Exporter::remove($this->work);
    }

    /** @return array<string, list<array<string, mixed>>> every table of the backup, with its rows in a fixed order */
    private function everything(Connection $db): array
    {
        $result = [];
        foreach (\PhpSso\Backup\BackupTables::ORDER as $table) {
            $rows = $db->pdo->query('SELECT * FROM "' . $table . '"')->fetchAll();
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
            $result[$table] = $rows;
        }

        return $result;
    }
}
