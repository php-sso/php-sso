<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Admin\Pager;

use PhpSso\Admin\Pager;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Pager::class, 'links')]
final class LinksTest extends TestCase
{
    /** @return array<string, array{int, int, list<int|null>}> */
    public static function cases(): array
    {
        return [
            'one page' => [1, 1, [1]],
            'few pages are all shown' => [2, 4, [1, 2, 3, 4]],
            'the start of many' => [1, 10, [1, 2, 3, null, 10]],
            'the middle of many' => [5, 10, [1, null, 3, 4, 5, 6, 7, null, 10]],
            'the end of many' => [10, 10, [1, null, 8, 9, 10]],
            'one page away from the edge has no gap' => [4, 10, [1, 2, 3, 4, 5, 6, null, 10]],
        ];
    }

    /** @param list<int|null> $expected */
    #[DataProvider('cases')]
    public function testItShowsTheFirstAndLastPageAndAWindowAroundTheCurrentOne(int $page, int $pages, array $expected): void
    {
        // Arrange (the case)

        // Act
        $links = Pager::links($page, $pages);

        // Assert
        $this->assertSame($expected, $links);
    }
}
