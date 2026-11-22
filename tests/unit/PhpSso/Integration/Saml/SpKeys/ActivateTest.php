<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Integration\Saml\SpKeys;

use PhpSso\Integration\Saml\SpKeys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesBackupFiles;

#[CoversMethod(SpKeys::class, 'activate')]
#[CoversMethod(SpKeys::class, 'discard')]
final class ActivateTest extends TestCase
{
    use UsesBackupFiles;

    public function testTheStagedKeyBecomesTheOneThatSignsAndTheOldOneIsGone(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $old = $keys->current();
        $keys->stage();
        $staged = $keys->current()?->nextCertificate;

        // Act
        $keys->activate();

        // Assert
        $current = $keys->current();
        $this->assertNotNull($old);
        $this->assertNotNull($current);
        $this->assertSame($staged, $current->certificate);
        $this->assertNotSame($old->privateKey, $current->privateKey);
        $this->assertNull($current->nextCertificate);
        $this->assertFileDoesNotExist($this->work . '/keys/saml-sp-next.key');
    }

    public function testItRefusesWhenNothingIsStaged(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stage one first');

        // Act
        $keys->activate();

        // Assert (the exception above)
    }

    public function testDiscardingRemovesTheStagedKeyAndKeepsTheCurrentOne(): void
    {
        // Arrange
        $keys = new SpKeys($this->work . '/keys', 'sso.example.com');
        $keys->ensure();
        $before = $keys->current()?->certificate;
        $keys->stage();

        // Act
        $keys->discard();

        // Assert
        $this->assertNull($keys->current()?->nextCertificate);
        $this->assertSame($before, $keys->current()?->certificate);
    }
}
