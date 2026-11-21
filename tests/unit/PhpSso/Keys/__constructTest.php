<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Keys;

use PhpSso\Keys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Keys::class, '__construct')]
final class __constructTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-sso-keys-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testItCreatesTheKeyPairOnFirstUse(): void
    {
        // Act
        $keys = new Keys($this->dir);

        // Assert
        $this->assertFileExists($this->dir . '/private.pem');
        $this->assertFileExists($this->dir . '/public.pem');
        $this->assertCount(1, $keys->provider->verificationKeys());
    }

    public function testThePrivateKeyAndItsDirectoryAreReadableOnlyByTheOwner(): void
    {
        // Act
        new Keys($this->dir);

        // Assert
        $this->assertSame(0600, fileperms($this->dir . '/private.pem') & 0777);
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testItLeavesNoTemporaryFilesBehind(): void
    {
        // Act
        new Keys($this->dir);

        // Assert
        $files = array_values(array_diff(scandir($this->dir) ?: [], ['.', '..', '.lock']));
        sort($files);
        $this->assertSame(['private.pem', 'public.pem'], $files);
    }

    public function testItReusesTheKeyItFindsSoTheKidStaysTheSame(): void
    {
        // Arrange
        $first = (new Keys($this->dir))->provider->signingKey()->kid;

        // Act
        $second = (new Keys($this->dir))->provider->signingKey()->kid;

        // Assert
        $this->assertSame($first, $second);
    }

    public function testThePublishedKeyBelongsToThePrivateKey(): void
    {
        // Act
        $provider = (new Keys($this->dir))->provider;

        // Assert
        $this->assertSame($provider->signingKey()->verificationKey()->toJwk()['n'], $provider->verificationKeys()[0]->toJwk()['n']);
        $this->assertSame(trim((string) file_get_contents($this->dir . '/public.pem')), trim($provider->verificationKeys()[0]->publicKeyPem));
    }
}
