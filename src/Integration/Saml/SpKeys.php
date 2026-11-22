<?php

declare(strict_types=1);

namespace PhpSso\Integration\Saml;

use PhpSso\Saml\ServiceProvider\SpSigningKey;
use RuntimeException;

/**
 * The key this server signs SAML sign-in requests with, kept in `var/keys` next to the token signing keys:
 *
 * - `saml-sp.key` and `saml-sp.crt`: the key in use and its certificate, made the first time a connection asks for signed requests.
 * - `saml-sp-next.key` and `saml-sp-next.crt`: a key that is staged. Its certificate is published in the metadata next to the
 *   current one so companies can add it to their provider; {@see activate()} makes it the key that signs.
 *
 * The keys are RSA 3072, with a self-signed certificate that lasts ten years: providers read the certificate from our
 * metadata and do not need an authority behind it.
 */
final class SpKeys
{
    private const CURRENT = 'saml-sp';
    private const NEXT = 'saml-sp-next';

    public function __construct(private string $dir, private string $commonName)
    {
    }

    /** The key to sign with, with the staged certificate as the next one, or null when none has been made yet. */
    public function current(): ?SpSigningKey
    {
        $key = $this->read(self::CURRENT);
        if ($key === null) {
            return null;
        }
        $next = $this->read(self::NEXT);

        return new SpSigningKey($key['key'], $key['certificate'], $next['certificate'] ?? null);
    }

    /** Makes the key if there is none yet. Safe to call from two requests at once. */
    public function ensure(): void
    {
        $this->locked(function (): void {
            if ($this->read(self::CURRENT) === null) {
                $this->write(self::CURRENT, SpSigningKey::generate($this->commonName));
            }
        });
    }

    /**
     * Makes a new key and publishes its certificate, without signing with it yet.
     *
     * @throws RuntimeException when one is already staged
     */
    public function stage(): void
    {
        $this->locked(function (): void {
            if ($this->read(self::CURRENT) === null) {
                throw new RuntimeException('There is no SAML signing key yet: it is made when a connection is set to sign its requests.');
            }
            if ($this->read(self::NEXT) !== null) {
                throw new RuntimeException('A new key is already staged: activate it, or discard it first.');
            }
            $this->write(self::NEXT, SpSigningKey::generate($this->commonName));
        });
    }

    /**
     * Makes the staged key the one that signs. The old key and certificate are removed.
     *
     * @throws RuntimeException when nothing is staged
     */
    public function activate(): void
    {
        $this->locked(function (): void {
            if ($this->read(self::NEXT) === null) {
                throw new RuntimeException('No new key is staged: stage one first.');
            }
            foreach (['key', 'crt'] as $extension) {
                if (!rename("{$this->dir}/" . self::NEXT . ".{$extension}", "{$this->dir}/" . self::CURRENT . ".{$extension}")) {
                    throw new RuntimeException('Could not put the new key in place.');
                }
            }
        });
    }

    /**
     * Removes the staged key.
     *
     * @throws RuntimeException when nothing is staged
     */
    public function discard(): void
    {
        $this->locked(function (): void {
            if ($this->read(self::NEXT) === null) {
                throw new RuntimeException('No new key is staged.');
            }
            @unlink("{$this->dir}/" . self::NEXT . '.key');
            @unlink("{$this->dir}/" . self::NEXT . '.crt');
        });
    }

    /** @return array{current: array{fingerprint: string, expires: int, certificate: string}|null, next: array{fingerprint: string, expires: int, certificate: string}|null} */
    public function status(): array
    {
        return ['current' => $this->describe($this->read(self::CURRENT)), 'next' => $this->describe($this->read(self::NEXT))];
    }

    /**
     * @param array{key: string, certificate: string}|null $pair
     * @return array{fingerprint: string, expires: int, certificate: string}|null
     */
    private function describe(?array $pair): ?array
    {
        if ($pair === null) {
            return null;
        }
        $info = openssl_x509_parse($pair['certificate']);

        return ['fingerprint' => SpSigningKey::fingerprint($pair['certificate']), 'expires' => is_array($info) ? (int) $info['validTo_time_t'] : 0, 'certificate' => $pair['certificate']];
    }

    /** @return array{key: string, certificate: string}|null */
    private function read(string $name): ?array
    {
        $key = @file_get_contents("{$this->dir}/{$name}.key");
        $certificate = @file_get_contents("{$this->dir}/{$name}.crt");

        return is_string($key) && is_string($certificate) && $key !== '' && $certificate !== '' ? ['key' => $key, 'certificate' => $certificate] : null;
    }

    /** @param array{privateKey: string, certificate: string} $pair */
    private function write(string $name, array $pair): void
    {
        // The certificate goes second, because a key without a certificate counts as nothing (see read()).
        $this->place("{$this->dir}/{$name}.key", $pair['privateKey'], 0600);
        $this->place("{$this->dir}/{$name}.crt", $pair['certificate'], 0644);
    }

    private function place(string $path, string $contents, int $mode): void
    {
        $previous = umask(0077);
        try {
            $temporary = tempnam($this->dir, '.tmp');
            if ($temporary === false || file_put_contents($temporary, $contents) === false || !chmod($temporary, $mode) || !rename($temporary, $path)) {
                throw new RuntimeException("Cannot write {$path}.");
            }
        } finally {
            umask($previous);
        }
    }

    private function locked(callable $work): mixed
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create the key directory {$this->dir}.");
        }
        $lock = fopen($this->dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the key directory.');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
