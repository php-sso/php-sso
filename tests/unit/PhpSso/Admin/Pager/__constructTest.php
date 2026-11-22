<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Admin\Pager;

use PhpSso\Admin\Pager;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Pager::class, '__construct')]
final class __constructTest extends TestCase
{
    public function testItCountsThePagesAndSaysWhereTheRequestedOneStarts(): void
    {
        // Arrange
        $total = 120;

        // Act
        $pager = new Pager($total, 50, '2');

        // Assert
        $this->assertSame(3, $pager->pages);
        $this->assertSame(2, $pager->page);
        $this->assertSame(50, $pager->offset);
    }

    public function testAPageBeyondTheLastIsTheLastAndOneBeforeTheFirstIsTheFirst(): void
    {
        // Arrange
        $total = 120;

        // Act
        $beyond = new Pager($total, 50, '99');
        $before = new Pager($total, 50, '-4');

        // Assert
        $this->assertSame(3, $beyond->page);
        $this->assertSame(100, $beyond->offset);
        $this->assertSame(1, $before->page);
        $this->assertSame(0, $before->offset);
    }

    public function testAPageThatIsNotANumberIsTheFirst(): void
    {
        // Arrange
        $requested = ['x'];

        // Act
        $pager = new Pager(120, 50, $requested);

        // Assert
        $this->assertSame(1, $pager->page);
    }

    public function testNothingToShowIsOnePage(): void
    {
        // Arrange
        $total = 0;

        // Act
        $pager = new Pager($total, 50, 1);

        // Assert
        $this->assertSame(1, $pager->pages);
        $this->assertSame(0, $pager->offset);
        $this->assertSame([1], $pager->links);
    }

    public function testAnExactMultipleDoesNotMakeAnEmptyLastPage(): void
    {
        // Arrange
        $total = 100;

        // Act
        $pager = new Pager($total, 50, 1);

        // Assert
        $this->assertSame(2, $pager->pages);
    }
}
