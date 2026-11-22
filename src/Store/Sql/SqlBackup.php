<?php

declare(strict_types=1);

namespace PhpSso\Store\Sql;

use PhpSso\Backup\Exporter;
use PhpSso\Backup\Importer;
use PhpSso\Store\Backup;

final class SqlBackup implements Backup
{
    public function __construct(private Connection $db)
    {
    }

    public function export(string $keysDir, string $target): array
    {
        return (new Exporter($this->db, $keysDir))->export($target);
    }

    public function import(string $archive, string $keysDir, bool $replace = false, bool $withKeys = true): array
    {
        return (new Importer($this->db, $keysDir))->import($archive, $replace, $withKeys);
    }
}
