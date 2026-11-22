<?php

declare(strict_types=1);

namespace PhpSso\Backup;

use PDO;
use PharData;
use PhpSso\Store\Sql\Connection;
use RuntimeException;

/**
 * Loads a backup made by the Exporter into a database of any kind (the archive does not care which one it came from) and writes its key files.
 * It refuses to touch a database that has people in it, or a key folder that has keys in it, unless it is told to replace them.
 */
final class Importer
{
    public function __construct(private Connection $db, private string $keysDir)
    {
    }

    /**
     * @param bool $replace empty the database (and move the existing keys aside) first
     * @param bool $withKeys also restore the key files
     * @return array{tables: int, rows: int, keyFiles: int}
     */
    public function import(string $archive, bool $replace = false, bool $withKeys = true): array
    {
        $work = sys_get_temp_dir() . '/php-sso-restore-' . bin2hex(random_bytes(6));
        $previous = umask(0077);
        try {
            mkdir($work, 0700, true);
            try {
                (new PharData($archive))->extractTo($work);
            } catch (\Throwable $e) {
                throw new RuntimeException("{$archive} is not a backup this can read: " . $e->getMessage(), 0, $e);
            }
            $manifest = $this->manifest($work);

            if (!$replace && (int) $this->db->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('The database already has people in it. Restore into an empty database, or say that it may be replaced.');
            }
            if ($withKeys && !$replace && is_file($this->keysDir . '/private.pem')) {
                throw new RuntimeException("{$this->keysDir} already has signing keys. Restore into a new installation, or say that they may be replaced.");
            }

            $rows = $this->loadTables($work . '/database', $manifest['tables'], $replace);
            $keyFiles = $withKeys ? $this->writeKeys($work . '/keys', $manifest['keys']) : 0;
        } finally {
            umask($previous);
            Exporter::remove($work);
        }

        return ['tables' => count($manifest['tables']), 'rows' => $rows, 'keyFiles' => $keyFiles];
    }

    /** @return array{tables: array<string, int>, keys: list<string>} */
    private function manifest(string $work): array
    {
        $manifest = is_file($work . '/manifest.json') ? json_decode((string) file_get_contents($work . '/manifest.json'), true) : null;
        if (!is_array($manifest) || ($manifest['app'] ?? null) !== 'php-sso' || ($manifest['format'] ?? null) !== 1 || !is_array($manifest['tables'] ?? null)) {
            throw new RuntimeException('This is not a php-sso backup (or one from a version that cannot be read).');
        }
        $unknown = array_diff(array_keys($manifest['tables']), BackupTables::ORDER);
        if ($unknown !== []) {
            throw new RuntimeException('The backup has tables this version does not know: ' . implode(', ', $unknown) . '. Restore it with the version that made it, or a newer one.');
        }

        return ['tables' => array_map('intval', $manifest['tables']), 'keys' => array_values(array_map('strval', (array) ($manifest['keys'] ?? [])))];
    }

    /** @param array<string, int> $expected */
    private function loadTables(string $dir, array $expected, bool $replace): int
    {
        $pdo = $this->db->pdo;
        $pdo->beginTransaction();
        try {
            if ($replace) {
                foreach (array_reverse(BackupTables::ORDER) as $table) {
                    $pdo->exec('DELETE FROM "' . $table . '"');
                }
            }
            $total = 0;
            foreach (BackupTables::ORDER as $table) {
                if (!isset($expected[$table])) {
                    continue; // a backup from before the table existed
                }
                $total += $this->loadTable($pdo, $table, $dir . '/' . $table . '.jsonl', $expected[$table]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->advanceSequences($pdo);

        return $total;
    }

    private function loadTable(PDO $pdo, string $table, string $file, int $expected): int
    {
        $handle = is_file($file) ? fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException("The backup has no data file for {$table}.");
        }
        $known = $this->columnsOf($pdo, $table);
        $insert = null;
        $columns = [];
        $rows = 0;
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($insert === null) {
                $columns = array_keys($row);
                $missing = array_diff($columns, $known);
                if ($missing !== []) {
                    throw new RuntimeException("The backup has columns of {$table} that this database does not have: " . implode(', ', $missing) . '. Restore it with the version that made it, or a newer one.');
                }
                $insert = $pdo->prepare('INSERT INTO "' . $table . '" ("' . implode('", "', $columns) . '") VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
            }
            $insert->execute(array_map(static fn (string $c) => $row[$c] ?? null, $columns));
            $rows++;
        }
        fclose($handle);
        if ($rows !== $expected) {
            throw new RuntimeException("The backup says {$table} has {$expected} rows but its file has {$rows}: it is damaged.");
        }

        return $rows;
    }

    /** @return list<string> */
    private function columnsOf(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SELECT * FROM "' . $table . '" WHERE 1 = 0');
        $names = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $names[] = (string) $statement->getColumnMeta($i)['name'];
        }

        return $names;
    }

    /** PostgreSQL does not move an identity column's counter when rows come with their ids: the next new row would reuse one. */
    private function advanceSequences(PDO $pdo): void
    {
        if ($this->db->dialect->name() !== 'pgsql') {
            return;
        }
        foreach (BackupTables::ORDER as $table) {
            if (!in_array('id', $this->columnsOf($pdo, $table), true)) {
                continue;
            }
            $sequence = $pdo->query("SELECT pg_get_serial_sequence('\"{$table}\"', 'id')")->fetchColumn();
            if (!is_string($sequence)) {
                continue; // an id that is text (login_sessions) has no counter
            }
            $max = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM "' . $table . '"')->fetchColumn();
            if ($max > 0) {
                $pdo->query("SELECT setval('{$sequence}', {$max})");
            }
        }
    }

    /** @param list<string> $files */
    private function writeKeys(string $from, array $files): int
    {
        $written = 0;
        if (is_file($this->keysDir . '/private.pem') || is_dir($this->keysDir . '/ring')) {
            $aside = $this->keysDir . '.before-restore-' . date('YmdHis');
            if (!rename($this->keysDir, $aside)) {
                throw new RuntimeException("Cannot move {$this->keysDir} aside.");
            }
        }
        if (!is_dir($this->keysDir) && !mkdir($this->keysDir, 0700, true) && !is_dir($this->keysDir)) {
            throw new RuntimeException("Cannot create {$this->keysDir}.");
        }
        foreach ($files as $relative) {
            if (preg_match(BackupTables::KEY_FILE, $relative) !== 1 || !is_file("{$from}/{$relative}")) {
                continue; // never a path the archive chose: only the known key files
            }
            if (!is_dir(dirname("{$this->keysDir}/{$relative}"))) {
                mkdir(dirname("{$this->keysDir}/{$relative}"), 0700, true);
            }
            copy("{$from}/{$relative}", "{$this->keysDir}/{$relative}");
            chmod("{$this->keysDir}/{$relative}", str_ends_with($relative, '.pub') || $relative === 'public.pem' ? 0644 : 0600);
            $written++;
        }

        return $written;
    }
}
