<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Sql\SqliteDialect;

use PhpSso\Store\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(SqliteDialect::class, 'introducedViolations')]
final class IntroducedViolationsTest extends TestCase
{
    public function testOrphansThatWereThereBeforeAreNotNew(): void
    {
        // Arrange
        $before = ['saml_domains|saml_connections|0' => 1];

        // Act
        $new = SqliteDialect::introducedViolations($before, $before);

        // Assert
        $this->assertSame([], $new);
    }

    public function testMoreOrphansInTheSameTableAreNew(): void
    {
        // Act
        $new = SqliteDialect::introducedViolations(['a|b|0' => 1], ['a|b|0' => 3]);

        // Assert
        $this->assertSame(['a|b|0' => 2], $new);
    }

    public function testAnOrphanInAnotherTableIsNew(): void
    {
        // Act
        $new = SqliteDialect::introducedViolations(['a|b|0' => 1], ['a|b|0' => 1, 'c|d|0' => 1]);

        // Assert
        $this->assertSame(['c|d|0' => 1], $new);
    }

    public function testFewerOrphansThanBeforeIsNotNewDamage(): void
    {
        // Act
        $new = SqliteDialect::introducedViolations(['a|b|0' => 2], []);

        // Assert
        $this->assertSame([], $new);
    }
}
