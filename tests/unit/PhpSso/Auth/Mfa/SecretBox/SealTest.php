<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Mfa\SecretBox;

use PhpSso\Auth\Mfa\SecretBox;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversMethod(SecretBox::class, 'seal')]
#[CoversMethod(SecretBox::class, 'open')]
final class SealTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sso-box-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testItOpensWhatItSealedAndTheSealedTextHidesTheSecret(): void
    {
        // Arrange
        $box = new SecretBox($this->dir . '/mfa.key');

        // Act
        $sealed = $box->seal('JBSWY3DPEHPK3PXP');

        // Assert
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $sealed);
        $this->assertSame('JBSWY3DPEHPK3PXP', $box->open($sealed));
    }

    public function testItCreatesTheKeyFileOnceWithOwnerOnlyAccessAndReusesIt(): void
    {
        // Arrange
        $sealed = (new SecretBox($this->dir . '/mfa.key'))->seal('secret');

        // Act
        $opened = (new SecretBox($this->dir . '/mfa.key'))->open($sealed);

        // Assert
        $this->assertSame('secret', $opened);
        $this->assertSame(32, filesize($this->dir . '/mfa.key'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir . '/mfa.key')), -4));
    }

    public function testItCannotOpenTextSealedWithAnotherKeyOrAlteredText(): void
    {
        // Arrange
        $sealed = (new SecretBox($this->dir . '/one.key'))->seal('secret');
        $other = new SecretBox($this->dir . '/two.key');
        $altered = substr($sealed, 0, -4) . 'AAAA';

        // Act
        $withOtherKey = $other->open($sealed);
        $tampered = (new SecretBox($this->dir . '/one.key'))->open($altered);

        // Assert
        $this->assertNull($withOtherKey);
        $this->assertNull($tampered);
        $this->assertNull($other->open('not base64 !!'));
    }

    public function testItRefusesAKeyFileOfTheWrongSize(): void
    {
        // Arrange
        mkdir($this->dir);
        file_put_contents($this->dir . '/bad.key', 'short');
        $this->expectException(RuntimeException::class);

        // Act
        (new SecretBox($this->dir . '/bad.key'))->seal('secret');

        // Assert (the exception above)
    }
}
