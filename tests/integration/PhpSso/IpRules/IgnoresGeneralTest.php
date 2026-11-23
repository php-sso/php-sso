<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\IpRules;

use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlIpRules;

#[CoversMethod(IpRules::class, 'ignoresGeneral')]
#[CoversMethod(IpRules::class, 'setIgnoresGeneral')]
final class IgnoresGeneralTest extends TestCase
{
    public function testItDefaultsToFollowingTheGeneralRulesAndRemembersAChange(): void
    {
        // Arrange
        $data = new TestData();
        $data->client('app');
        $rules = new SqlIpRules($data->db);

        // Act
        $before = $rules->ignoresGeneral('app');
        $rules->setIgnoresGeneral('app', true);
        $after = $rules->ignoresGeneral('app');

        // Assert
        $this->assertSame([false, true], [$before, $after]);
    }
}
