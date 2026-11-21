<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\IpRules;

use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;
use PhpSso\Store\Sqlite\SqliteIpRules;

#[CoversMethod(IpRules::class, 'ignoresGeneral')]
#[CoversMethod(IpRules::class, 'setIgnoresGeneral')]
final class IgnoresGeneralTest extends TestCase
{
    public function testItDefaultsToFollowingTheGeneralRulesAndRemembersAChange(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $rules = new SqliteIpRules($data->db);

        // Act
        $before = $rules->ignoresGeneral('app');
        $rules->setIgnoresGeneral('app', true);
        $after = $rules->ignoresGeneral('app');

        // Assert
        $this->assertSame([false, true], [$before, $after]);
    }
}
