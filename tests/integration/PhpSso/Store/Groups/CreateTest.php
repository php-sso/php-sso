<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Groups;

use PhpSso\Store\DuplicateValue;
use PhpSso\Store\Groups;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Groups::class, 'create')]
final class CreateTest extends TestCase
{
    public function testAGroupNameDifferingOnlyInCaseIsTaken(): void
    {
        // Arrange
        $data = new TestData();
        $data->group('Staff');
        $this->expectException(DuplicateValue::class);

        // Act
        $data->groups->create('STAFF', '');

        // Assert (the exception above)
    }

    public function testItReturnsTheIdOfTheNewGroup(): void
    {
        // Arrange
        $data = new TestData();
        $first = $data->groups->create('One', '');

        // Act
        $second = $data->groups->create('Two', '');

        // Assert
        $this->assertSame($first + 1, $second);
    }
}
