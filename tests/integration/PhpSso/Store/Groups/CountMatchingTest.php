<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Store\Groups;

use PhpSso\Store\Groups;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(Groups::class, 'countMatching')]
final class CountMatchingTest extends TestCase
{
    public function testItCountsAllGroupsWithoutTextAndOnlyTheMatchesWithIt(): void
    {
        // Arrange
        $data = new TestData();
        $data->groups->create('Finance', '');
        $data->groups->create('Field staff', '');
        $data->groups->create('Admins', '');

        // Act
        $all = $data->groups->countMatching('');
        $matching = $data->groups->countMatching('fi');

        // Assert
        $this->assertSame(3, $all);
        $this->assertSame(2, $matching);
    }
}
