<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Keys;

use PhpSso\Keys;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesKeyDirectory;

#[CoversMethod(Keys::class, 'status')]
final class StatusTest extends TestCase
{
    use UsesKeyDirectory;

    private const NOW = 1_800_000_000;
    private const KEEP = 86400;

    /** @return list<string> */
    private function states(int $at): array
    {
        return array_column((new Keys($this->dir, self::KEEP, $at))->status(), 'state');
    }

    public function testAFreshKeyIsTheSigningKey(): void
    {
        // Arrange
        new Keys($this->dir, self::KEEP, self::NOW);

        // Act
        $states = $this->states(self::NOW);

        // Assert
        $this->assertSame(['signing'], $states);
    }

    public function testARotationIsPendingUntilItsTime(): void
    {
        // Arrange
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);

        // Act
        $states = $this->states(self::NOW + 10);

        // Assert
        $this->assertSame(['signing', 'pending'], $states);
    }

    public function testTheOldKeyIsRetiredThenExpiredAfterTheRetentionPeriod(): void
    {
        // Arrange
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);

        // Act
        $retired = $this->states(self::NOW + 3600);
        $expired = $this->states(self::NOW + 3600 + self::KEEP);

        // Assert
        $this->assertSame(['retired', 'signing'], $retired);
        $this->assertSame(['expired', 'signing'], $expired);
    }

    public function testItSaysUntilWhenARetiredKeyIsPublished(): void
    {
        // Arrange
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);

        // Act
        $rows = (new Keys($this->dir, self::KEEP, self::NOW + 3600))->status();

        // Assert
        $this->assertSame(self::NOW + 3600 + self::KEEP, $rows[0]['publishedUntil']);
        $this->assertNull($rows[1]['publishedUntil']);
    }
}
