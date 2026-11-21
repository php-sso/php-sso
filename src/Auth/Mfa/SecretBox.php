<?php

declare(strict_types=1);

namespace PhpSso\Auth\Mfa;

use RuntimeException;

/**
 * Encrypts secrets (AES-256-GCM) before they go into the database, with a key kept in a file outside it.
 * The key is generated on first use. Losing the file makes every sealed secret unreadable, so back it up with the
 * rest of var/keys, and never commit it.
 */
final class SecretBox
{
    private ?string $key = null;

    public function __construct(private string $keyFile)
    {
    }

    public function seal(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt a secret.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /** @return string|null the secret, or null when the text was not sealed with this key or was altered */
    public function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }
        if (!is_file($this->keyFile)) {
            if (!is_dir(dirname($this->keyFile))) {
                mkdir(dirname($this->keyFile), 0775, true);
            }
            file_put_contents($this->keyFile, random_bytes(32), LOCK_EX);
            chmod($this->keyFile, 0600);
        }
        $key = (string) file_get_contents($this->keyFile);
        if (strlen($key) !== 32) {
            throw new RuntimeException("The key file {$this->keyFile} must hold exactly 32 bytes.");
        }

        return $this->key = $key;
    }
}
