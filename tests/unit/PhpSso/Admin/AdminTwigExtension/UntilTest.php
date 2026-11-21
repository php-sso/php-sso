<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Admin\AdminTwigExtension;

use PhpSso\Admin\AdminTwigExtension;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AdminTwigExtension::class, 'until')]
final class UntilTest extends TestCase
{
    /** @return array<string, array{int, string}> seconds from now, text */
    public static function cases(): array
    {
        return [
            'already past' => [-5, 'now'],
            'under a minute rounds up' => [20, 'in 1 min'],
            'ten minutes' => [600, 'in 10 min'],
            'two hours' => [7200, 'in 2 h'],
            'three days' => [3 * 86400, 'in 3 d'],
        ];
    }

    #[DataProvider('cases')]
    public function testItDescribesHowLongUntilTheTime(int $seconds, string $expected): void
    {
        // Arrange
        $timestamp = time() + $seconds;

        // Act
        $text = AdminTwigExtension::until($timestamp);

        // Assert
        $this->assertSame($expected, $text);
    }
}
