<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Store\Settings;

use PhpSso\Store\Settings;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlSettings;

#[CoversMethod(Settings::class, 'set')]
#[CoversMethod(Settings::class, 'get')]
final class SetTest extends TestCase
{
    public function testItReturnsNullForASettingThatWasNeverSaved(): void
    {
        // Arrange
        $settings = new SqlSettings((new TestData())->db);

        // Act
        $value = $settings->get('missing');

        // Assert
        $this->assertNull($value);
    }

    public function testItReplacesTheValueOfASavedSetting(): void
    {
        // Arrange
        $settings = new SqlSettings((new TestData())->db);
        $settings->set('colour', 'red');

        // Act
        $settings->set('colour', 'blue');

        // Assert
        $this->assertSame('blue', $settings->get('colour'));
    }
}
