<?php

declare(strict_types=1);

namespace PhpSso;

use PhpSso\OAuthServer\Jwt\KeyProvider;
use PhpSso\OAuthServer\Jwt\SigningKey;
use PhpSso\OAuthServer\Jwt\StaticKeyProvider;
use PhpSso\OAuthServer\Jwt\VerificationKey;

/**
 * The RSA signing keys (access tokens, ID tokens and logout tokens), handed to the OAuth server as a key provider. Kept in
 * var/keys; never commit them.
 *
 * The first key is generated on first use as `private.pem` and `public.pem`. A rotation adds a key to `ring/`, named by when
 * it starts signing and its kid (`<time>-<kid>.pub`, and `.key` for the private half). It is published in the JWKS at once but
 * only signs from that time on, so apps that cache the JWKS have it before they see a token signed with it. The key that was
 * signing is then retired: it signs nothing, but stays published for the retention period, so tokens it signed still verify.
 * `prune()` removes the private halves of retired keys and the public halves of expired ones.
 *
 * @phpstan-type Entry array{kid: string, activeFrom: int, public: string, publicPath: string, privatePath: string|null}
 */
final class Keys
{
    public readonly KeyProvider $provider;

    /** @param int $retentionSeconds how long a retired key stays published (tokens it signed are valid at most that long) */
    public function __construct(private string $dir, private int $retentionSeconds = 30 * 86400, private ?int $now = null)
    {
        $this->now ??= time();
        if ($this->entries() === []) {
            $this->generate();
        }

        $this->provider = $this->buildProvider($this->now);
    }

    /**
     * Adds a key that starts signing `$delay` seconds from now. It is published immediately.
     *
     * @return array{kid: string, activeFrom: int}
     * @throws \RuntimeException when a rotation is already waiting to take effect
     */
    public function rotate(int $delay): array
    {
        return $this->locked(function () use ($delay): array {
            foreach ($this->entries() as $entry) {
                if ($entry['activeFrom'] > $this->now) {
                    throw new \RuntimeException("A new key ({$entry['kid']}) is already waiting: it starts signing at " . gmdate('Y-m-d H:i:s', $entry['activeFrom']) . ' UTC.');
                }
            }

            [$private, $public] = $this->newPair();
            $kid = self::kidOf($public);
            $activeFrom = $this->now + max(0, $delay);
            $ring = $this->dir . '/ring';
            if (!is_dir($ring) && !mkdir($ring, 0700, true) && !is_dir($ring)) {
                throw new \RuntimeException("Cannot create {$ring}.");
            }
            $this->place("{$ring}/{$activeFrom}-{$kid}.pub", $public, 0644); // the public half first: a private key on disk always has its pair
            $this->place("{$ring}/{$activeFrom}-{$kid}.key", $private, 0600);

            return ['kid' => $kid, 'activeFrom' => $activeFrom];
        });
    }

    /**
     * The keys, oldest first. `state` is `pending` (published, not signing yet), `signing`, `retired` (published, signs nothing) or
     * `expired` (past the retention period: only `prune()` is left to do).
     *
     * @return list<array{kid: string, activeFrom: int, state: string, hasPrivateKey: bool, publishedUntil: int|null}>
     */
    public function status(): array
    {
        $entries = $this->entries();
        $signing = $this->signingEntry($entries, $this->now);
        $rows = [];
        foreach ($entries as $i => $entry) {
            $until = null;
            if ($entry['activeFrom'] > $this->now) {
                $state = 'pending';
            } elseif ($entry['kid'] === $signing['kid']) {
                $state = 'signing';
            } else {
                $until = $this->retiredAt($entries, $i) + $this->retentionSeconds;
                $state = $this->now < $until ? 'retired' : 'expired';
            }
            $rows[] = ['kid' => $entry['kid'], 'activeFrom' => $entry['activeFrom'], 'state' => $state, 'hasPrivateKey' => $entry['privatePath'] !== null, 'publishedUntil' => $until];
        }

        return $rows;
    }

    /**
     * Removes the private half of every retired key (it can never sign again) and every key past the retention period.
     *
     * @return array{privateRemoved: int, removed: int}
     */
    public function prune(): array
    {
        return $this->locked(function (): array {
            $entries = $this->entries();
            $signing = $this->signingEntry($entries, $this->now);
            $privateRemoved = $removed = 0;
            foreach ($entries as $i => $entry) {
                if ($entry['activeFrom'] > $this->now || $entry['kid'] === $signing['kid']) {
                    continue;
                }
                if ($entry['privatePath'] !== null && unlink($entry['privatePath'])) {
                    $privateRemoved++;
                }
                if ($this->now >= $this->retiredAt($entries, $i) + $this->retentionSeconds && unlink($entry['publicPath'])) {
                    $removed++;
                }
            }

            return ['privateRemoved' => $privateRemoved, 'removed' => $removed];
        });
    }

    /** @param list<Entry> $entries */
    private function signingEntry(array $entries, int $now): array
    {
        $signing = null;
        foreach ($entries as $entry) {
            if ($entry['activeFrom'] <= $now && $entry['privatePath'] !== null) {
                $signing = $entry; // oldest first, so the newest one that may sign wins
            }
        }

        return $signing ?? throw new \RuntimeException('There is no signing key that may be used now: the private key of the current one is missing from ' . $this->dir . '.');
    }

    /** @param list<Entry> $entries when the key at `$index` stopped signing: the moment the next one started */
    private function retiredAt(array $entries, int $index): int
    {
        return $entries[$index + 1]['activeFrom'] ?? PHP_INT_MAX - $this->retentionSeconds;
    }

    private function buildProvider(int $now): KeyProvider
    {
        $entries = $this->entries();
        $signing = $this->signingEntry($entries, $now);
        $published = [];
        foreach ($entries as $i => $entry) {
            if ($entry['kid'] === $signing['kid']) {
                continue;
            }
            $stillPublished = $entry['activeFrom'] > $now || $now < $this->retiredAt($entries, $i) + $this->retentionSeconds;
            if ($stillPublished) {
                $published[] = new VerificationKey($entry['kid'], $entry['public']);
            }
        }

        return new StaticKeyProvider(new SigningKey($signing['kid'], (string) file_get_contents((string) $signing['privatePath'])), $published);
    }

    /** @return list<Entry> oldest first */
    private function entries(): array
    {
        $entries = [];
        if (is_file($this->dir . '/public.pem')) {
            $public = (string) file_get_contents($this->dir . '/public.pem');
            $entries[] = ['kid' => self::kidOf($public), 'activeFrom' => 0, 'public' => $public, 'publicPath' => $this->dir . '/public.pem',
                'privatePath' => is_file($this->dir . '/private.pem') ? $this->dir . '/private.pem' : null];
        }
        foreach (glob($this->dir . '/ring/*.pub') ?: [] as $path) {
            if (preg_match('/^(\d+)-([0-9a-f]{16})\.pub$/', basename($path), $m) !== 1) {
                continue;
            }
            $private = substr($path, 0, -4) . '.key';
            $entries[] = ['kid' => $m[2], 'activeFrom' => (int) $m[1], 'public' => (string) file_get_contents($path), 'publicPath' => $path,
                'privatePath' => is_file($private) ? $private : null];
        }
        usort($entries, static fn (array $a, array $b): int => $a['activeFrom'] <=> $b['activeFrom']);

        return $entries;
    }

    private static function kidOf(string $publicPem): string
    {
        return substr(hash('sha256', $publicPem), 0, 16);
    }

    /** @return array{string, string} the private and the public key, as PEM */
    private function newPair(): array
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pair === false || !openssl_pkey_export($pair, $private)) {
            throw new \RuntimeException('Cannot generate a signing key.');
        }
        $public = openssl_pkey_get_details($pair)['key'] ?? throw new \RuntimeException('Cannot read the public key.');

        return [(string) $private, (string) $public];
    }

    /**
     * Two requests can arrive before the key exists, so this runs under a lock and the second one finds the first one's
     * key. Nothing is ever readable by others, not even for a moment: files are created private, written under a
     * temporary name and moved into place, the public key first so that a private key on disk always has its pair.
     */
    private function generate(): void
    {
        $this->locked(function (): void {
            if ($this->entries() !== []) {
                return; // another request made it while this one waited
            }

            [$private, $public] = $this->newPair();
            $this->place($this->dir . '/public.pem', $public, 0644);
            $this->place($this->dir . '/private.pem', $private, 0600);
        });
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the key directory {$this->dir}.");
        }

        $lock = fopen($this->dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the key directory.');
        }

        try {
            return $work();
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
