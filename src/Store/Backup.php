<?php

declare(strict_types=1);

namespace PhpSso\Store;

/** Everything a server needs to start again somewhere else, in one file, and loading it back. */
interface Backup
{
    /**
     * Writes the data and the key files to `$target` (a `.tar.gz`), without stopping the server.
     *
     * @return array{tables: int, rows: int, keyFiles: int}
     * @throws \RuntimeException when the file exists already or cannot be written
     */
    public function export(string $keysDir, string $target): array;

    /**
     * Loads a file made by {@see export()}, from any kind of storage.
     *
     * @param bool $replace empty the storage (and move the existing keys aside) first; otherwise it must be empty
     * @param bool $withKeys also restore the key files
     * @return array{tables: int, rows: int, keyFiles: int}
     * @throws \RuntimeException when the file cannot be read, does not fit, or the storage is not empty
     */
    public function import(string $archive, string $keysDir, bool $replace = false, bool $withKeys = true): array;
}
