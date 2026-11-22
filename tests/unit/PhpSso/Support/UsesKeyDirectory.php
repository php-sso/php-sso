<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/** A key folder that exists only for the test. */
trait UsesKeyDirectory
{
    private string $dir;

    #[Before]
    protected function makeKeyDirectory(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-sso-keys-' . bin2hex(random_bytes(6));
    }

    #[After]
    protected function removeKeyDirectory(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
                if (in_array(basename($entry), ['.', '..'], true)) {
                    continue;
                }
                is_dir($entry) ? $remove($entry) : unlink($entry);
            }
            if (is_dir($path)) {
                rmdir($path);
            }
        };
        $remove($this->dir);
    }

    /** @return list<string> the kids of every published key, signing key first */
    private function publishedKids(\PhpSso\Keys $keys): array
    {
        return array_map(static fn ($key): string => $key->kid, $keys->provider->verificationKeys());
    }
}
