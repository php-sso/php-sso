<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\SpKeys;

use PhpSso\Integration\Saml\SpKeys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(SpKeys::class, 'stage')]
final class StageTest extends TestCase
{
    use UsesBackupFiles;

    public function testTheStagedCertificateIsPublishedNextToTheCurrentOneButDoesNotSign(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $before = $keys->current();

        // Act
        $keys->stage();

        // Assert
        $after = $keys->current();
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame($before->certificate, $after->certificate);
        $this->assertSame($before->privateKey, $after->privateKey);
        $this->assertNotNull($after->nextCertificate);
        $this->assertNotSame($before->certificate, $after->nextCertificate);
    }

    public function testItRefusesWhenThereIsNoKeyYet(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no SAML signing key');

        // Act
        $keys->stage();

        // Assert (the exception above)
    }

    public function testItRefusesWhenOneIsAlreadyStaged(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $keys->stage();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already staged');

        // Act
        $keys->stage();

        // Assert (the exception above)
    }
}
