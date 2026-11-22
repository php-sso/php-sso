<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Keys;

use PhpSso\Keys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesKeyDirectory;

#[CoversMethod(Keys::class, 'prune')]
final class PruneTest extends TestCase
{
    use UsesKeyDirectory;

    private const NOW = 1_800_000_000;
    private const KEEP = 86400;

    public function testItKeepsEverythingWhileARotationIsStillPending(): void
    {
        // Arrange
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);

        // Act
        $result = (new Keys($this->dir, self::KEEP, self::NOW + 10))->prune();

        // Assert
        $this->assertSame(['privateRemoved' => 0, 'removed' => 0], $result);
        $this->assertFileExists($this->dir . '/private.pem');
    }

    public function testItRemovesThePrivateKeyOfARetiredKeyButKeepsItPublished(): void
    {
        // Arrange
        $oldKid = (new Keys($this->dir, self::KEEP, self::NOW))->provider->signingKey()->kid;
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(60);
        $later = self::NOW + 60;

        // Act
        $result = (new Keys($this->dir, self::KEEP, $later))->prune();

        // Assert
        $this->assertSame(['privateRemoved' => 1, 'removed' => 0], $result);
        $this->assertFileDoesNotExist($this->dir . '/private.pem');
        $this->assertContains($oldKid, $this->publishedKids(new Keys($this->dir, self::KEEP, $later)));
    }

    public function testItRemovesAKeyThatIsPastTheRetentionPeriodAndTheServerKeepsSigning(): void
    {
        // Arrange
        $keys = new Keys($this->dir, self::KEEP, self::NOW);
        $oldKid = $keys->provider->signingKey()->kid;
        $new = $keys->rotate(60);
        $end = self::NOW + 60 + self::KEEP;

        // Act
        $result = (new Keys($this->dir, self::KEEP, $end))->prune();
        $after = new Keys($this->dir, self::KEEP, $end);

        // Assert
        $this->assertSame(['privateRemoved' => 1, 'removed' => 1], $result);
        $this->assertFileDoesNotExist($this->dir . '/public.pem');
        $this->assertSame($new['kid'], $after->provider->signingKey()->kid);
        $this->assertNotContains($oldKid, $this->publishedKids($after));
        $this->assertCount(1, glob($this->dir . '/ring/*.key'), 'no new key was generated');
    }

    public function testThereIsNothingToRemoveWithASingleKey(): void
    {
        // Arrange
        $keys = new Keys($this->dir, self::KEEP, self::NOW);

        // Act
        $result = $keys->prune();

        // Assert
        $this->assertSame(['privateRemoved' => 0, 'removed' => 0], $result);
    }
}
