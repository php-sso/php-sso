<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\IpPolicy;

use PhpSso\IpPolicy;
use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(IpPolicy::class, 'explain')]
final class ExplainTest extends TestCase
{
    /** @return array<string, array{string[], string[], string, bool, string}> allow, deny, address, allowed, reason */
    public static function cases(): array
    {
        return [
            'no rules' => [[], [], '8.8.8.8', true, 'There are no rules.'],
            'only deny rules, none match' => [[], ['198.51.100.0/24'], '8.8.8.8', true, 'No deny rule matches, and there are no allow rules.'],
            'deny rule matches' => [[], ['198.51.100.0/24'], '198.51.100.5', false, 'Matches the deny rule 198.51.100.0/24.'],
            'allow rule matches' => [['203.0.113.0/24'], [], '203.0.113.9', true, 'Matches the allow rule 203.0.113.0/24.'],
            'allow rules exist, none match' => [['203.0.113.0/24'], [], '8.8.8.8', false, 'Allow rules exist and none of them match.'],
            'deny beats allow' => [['203.0.113.0/24'], ['203.0.113.9'], '203.0.113.9', false, 'Matches the deny rule 203.0.113.9.'],
        ];
    }

    /**
     * @param string[] $allow
     * @param string[] $deny
     */
    #[DataProvider('cases')]
    public function testItNamesTheRuleBehindTheDecision(array $allow, array $deny, string $ip, bool $allowed, string $reason): void
    {
        // Arrange
        $rules = $this->createStub(IpRules::class);
        $rules->method('ranges')->willReturnCallback(static fn (string $type): array => $type === 'allow' ? $allow : $deny);
        $policy = new IpPolicy($rules);

        // Act
        $result = $policy->explain($ip);

        // Assert
        $this->assertSame(['allowed' => $allowed, 'reason' => $reason], $result);
    }
}
