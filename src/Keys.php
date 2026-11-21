<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\OAuthServer\Jwt\KeyProvider;
use PhpSso\OAuthServer\Jwt\SigningKey;
use PhpSso\OAuthServer\Jwt\StaticKeyProvider;

/**
 * The RSA signing key pair (access tokens, ID tokens and logout tokens), handed to the OAuth server as a key
 * provider. Generated on first use and kept in var/keys. Never commit these.
 */
final class Keys
{
    public readonly KeyProvider $provider;

    public function __construct(string $dir)
    {
        if (!is_file($dir . '/private.pem')) {
            $this->generate($dir);
        }

        $kid = substr(hash('sha256', (string) file_get_contents($dir . '/public.pem')), 0, 16);
        $this->provider = new StaticKeyProvider(new SigningKey($kid, (string) file_get_contents($dir . '/private.pem')));
    }

    /**
     * Two requests can arrive before the key exists, so this runs under a lock and the second one finds the first one's
     * key. Nothing is ever readable by others, not even for a moment: files are created private, written under a
     * temporary name and moved into place, the public key first so that a private key on disk always has its pair.
     */
    private function generate(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the key directory {$dir}.");
        }

        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the key directory.');
        }

        try {
            if (is_file($dir . '/private.pem')) {
                return; // another request made it while this one waited
            }

            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($pair === false || !openssl_pkey_export($pair, $private)) {
                throw new \RuntimeException('Cannot generate the signing key.');
            }
            $public = openssl_pkey_get_details($pair)['key'] ?? throw new \RuntimeException('Cannot read the public key.');

            $this->place($dir . '/public.pem', (string) $public, 0644);
            $this->place($dir . '/private.pem', (string) $private, 0600);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Writes the file under a temporary name that only this user can read, then renames it. */
    private function place(string $path, string $contents, int $mode): void
    {
        $previous = umask(0077);
        try {
            $temporary = tempnam(dirname($path), '.key');
            if ($temporary === false || file_put_contents($temporary, $contents) === false || !chmod($temporary, $mode) || !rename($temporary, $path)) {
                throw new \RuntimeException("Cannot write {$path}.");
            }
        } finally {
            umask($previous);
        }
    }
}
