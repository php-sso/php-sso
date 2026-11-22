<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Users;

use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(Users::class, 'countMatching')]
final class CountMatchingTest extends TestCase
{
    public function testItCountsEveryoneWithoutTextAndOnlyTheMatchesWithIt(): void
    {
        // Arrange
        $data = new TestData();
        foreach (['alice', 'alicia', 'bob'] as $name) {
            $data->user($name);
        }

        // Act
        $all = $data->users->countMatching('');
        $matching = $data->users->countMatching('ali');
        $none = $data->users->countMatching('zzz');

        // Assert
        $this->assertSame(3, $all);
        $this->assertSame(2, $matching);
        $this->assertSame(0, $none);
    }

    public function testSpacesAroundTheTextAreIgnored(): void
    {
        // Arrange
        $data = new TestData();
        $data->user('alice');

        // Act
        $count = $data->users->countMatching('  alice  ');

        // Assert
        $this->assertSame(1, $count);
    }
}
