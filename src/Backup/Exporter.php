<?php

declare(strict_types=1);

namespace PhpSso\Backup;

use PDO;
use Phar;
use PharData;
use PhpSso\Store\Sql\Connection;
use RuntimeException;

/**
 * Writes everything the server needs to start again somewhere else into one `.tar.gz`: the data of every table as JSON Lines (so the
 * same archive restores into SQLite, MySQL or PostgreSQL) and the files of `var/keys`. The tables are read in one snapshot, so the archive is
 * consistent while the server keeps running. The archive holds password hashes and the signing keys: keep it as private as the server itself.
 */
final class Exporter
{
    public function __construct(private Connection $db, private string $keysDir)
    {
    }

    /**
     * @param string $target where to write; must end in `.tar.gz`
     * @return array{tables: int, rows: int, keyFiles: int}
     */
    public function export(string $target, ?int $now = null): array
    {
        if (!str_ends_with($target, '.tar.gz')) {
            throw new RuntimeException('The backup file name must end in .tar.gz.');
        }
        if (file_exists($target)) {
            throw new RuntimeException("{$target} exists already: a backup never overwrites one.");
        }

        $work = sys_get_temp_dir() . '/php-sso-backup-' . bin2hex(random_bytes(6));
        $previous = umask(0077);
        try {
            mkdir($work . '/database', 0700, true);
            $counts = $this->dumpTables($work . '/database');
            $keyFiles = $this->copyKeys($work . '/keys');
            file_put_contents($work . '/manifest.json', json_encode([
                'format' => 1,
                'app' => 'php-sso',
                'createdAt' => $now ?? time(),
                'driver' => $this->db->dialect->name(),
                'tables' => $counts,
                'keys' => $keyFiles,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            $tar = substr($target, 0, -3);
            $archive = new PharData($tar);
            $archive->buildFromDirectory($work);
            $archive->compress(Phar::GZ);
            unset($archive);
            Phar::unlinkArchive($tar);
            chmod($target, 0600);
        } finally {
            umask($previous);
            self::remove($work);
            if (isset($tar) && is_file($tar)) {
                unlink($tar);
            }
        }

        return ['tables' => count($counts), 'rows' => array_sum($counts), 'keyFiles' => count($keyFiles)];
    }

    /** @return array<string, int> table => how many rows were written */
    private function dumpTables(string $dir): array
    {
        $pdo = $this->db->pdo;
        $this->beginSnapshot($pdo);
        try {
            $counts = [];
            foreach (BackupTables::ORDER as $table) {
                $handle = fopen("{$dir}/{$table}.jsonl", 'wb');
                if ($handle === false) {
                    throw new RuntimeException("Cannot write {$table}.");
                }
                $rows = 0;
                foreach ($pdo->query('SELECT * FROM "' . $table . '"') as $row) {
                    fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                    $rows++;
                }
                fclose($handle);
                $counts[$table] = $rows;
            }
        } finally {
            $pdo->commit(); // nothing was written: this only ends the snapshot
        }

        return $counts;
    }

    /** One view of every table, whatever the server does meanwhile. */
    private function beginSnapshot(PDO $pdo): void
    {
        match ($this->db->dialect->name()) {
            'mysql' => $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT'),
            'pgsql' => $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'),
            default => $pdo->exec('BEGIN'), // SQLite: a read transaction sees the database as it was at its first read
        };
    }

    /** @return list<string> the key files copied, relative to the key folder */
    private function copyKeys(string $to): array
    {
        $copied = [];
        $candidates = array_merge(glob($this->keysDir . '/*') ?: [], glob($this->keysDir . '/ring/*') ?: []);
        foreach ($candidates as $path) {
            $relative = substr($path, strlen($this->keysDir) + 1);
            if (!is_file($path) || preg_match(BackupTables::KEY_FILE, $relative) !== 1) {
                continue;
            }
            if (!is_dir(dirname("{$to}/{$relative}"))) {
                mkdir(dirname("{$to}/{$relative}"), 0700, true);
            }
            copy($path, "{$to}/{$relative}");
            $copied[] = $relative;
        }
        sort($copied);

        return $copied;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (!in_array(basename($entry), ['.', '..'], true)) {
                self::remove($entry);
            }
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
