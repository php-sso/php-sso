<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\HttpCache;

use PhpSso\Store\HttpCache;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(HttpCache::class, 'get')]
#[CoversMethod(HttpCache::class, 'put')]
#[CoversMethod(HttpCache::class, 'entry')]
final class GetTest extends TestCase
{
    public function testWhatWasSavedComesBackWhileItIsFresh(): void
    {
        // Arrange
        $cache = new HttpCache((new TestData())->db);
        $cache->put('k', ['a' => 1], 1000);

        // Act
        $fresh = $cache->get('k', 3600, 1000 + 3599);
        $stale = $cache->get('k', 3600, 1000 + 3601);

        // Assert
        $this->assertSame(['a' => 1], $fresh);
        $this->assertNull($stale);
    }

    public function testSavingAgainReplacesTheOldAnswerAndItsAge(): void
    {
        // Arrange
        $cache = new HttpCache((new TestData())->db);
        $cache->put('k', ['a' => 1], 1000);

        // Act
        $cache->put('k', ['a' => 2], 5000);

        // Assert
        $this->assertSame(['body' => ['a' => 2], 'fetched_at' => 5000], $cache->entry('k'));
    }

    public function testAnUnknownKeyHasNothing(): void
    {
        // Arrange
        $cache = new HttpCache((new TestData())->db);

        // Act
        $results = [$cache->get('nope', 3600), $cache->entry('nope')];

        // Assert
        $this->assertSame([null, null], $results);
    }

    public function testEntryShowsAnOldAnswerToo(): void
    {
        // Arrange
        $cache = new HttpCache((new TestData())->db);
        $cache->put('k', ['a' => 1], 10);

        // Act
        $entry = $cache->entry('k');

        // Assert
        $this->assertSame(10, $entry['fetched_at']);
    }
}
