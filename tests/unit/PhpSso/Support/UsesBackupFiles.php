<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Backup\Exporter;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/** A folder for the archives and key files of a test, removed afterwards (no database in it: see ReadsEveryTable for that). */
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
}
