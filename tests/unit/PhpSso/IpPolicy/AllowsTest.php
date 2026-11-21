<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\IpPolicy;

use PhpSso\IpPolicy;
use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(IpPolicy::class, 'allows')]
#[CoversMethod(IpPolicy::class, 'allowsAfterAdding')]
final class AllowsTest extends TestCase
{
    /** @return array<string, array{string[], string[], string, bool}> allow ranges, deny ranges, address, expected */
    public static function cases(): array
    {
        return [
            'no rules allows everyone' => [[], [], '8.8.8.8', true],
            'denied address is blocked' => [[], ['198.51.100.0/24'], '198.51.100.5', false],
            'address outside deny range is allowed' => [[], ['198.51.100.0/24'], '8.8.8.8', true],
            'allow list admits matching address' => [['203.0.113.0/24'], [], '203.0.113.9', true],
            'allow list rejects other addresses' => [['203.0.113.0/24'], [], '8.8.8.8', false],
            'deny wins over allow' => [['203.0.113.0/24'], ['203.0.113.9'], '203.0.113.9', false],
        ];
    }

    /**
     * @param string[] $allow
     * @param string[] $deny
     */
    #[DataProvider('cases')]
    public function testItAppliesDenyBeforeAllow(array $allow, array $deny, string $ip, bool $expected): void
    {
        // Arrange
        $policy = new IpPolicy($this->rules($allow, $deny));

        // Act
        $result = $policy->allows($ip);

        // Assert
        $this->assertSame($expected, $result);
    }

    public function testItKeepsClientRulesSeparateFromGlobalRules(): void
    {
        // Arrange
        $rules = $this->createStub(IpRules::class);
        $rules->method('ranges')->willReturnCallback(static fn (string $type, ?string $clientId = null): array => $type === 'deny' && $clientId === 'app-1' ? ['198.51.100.0/24'] : []);
        $policy = new IpPolicy($rules);

        // Act
        $results = [$policy->allows('198.51.100.5', 'app-1'), $policy->allows('198.51.100.5', 'app-2'), $policy->allows('198.51.100.5')];

        // Assert
        $this->assertSame([false, true, true], $results);
    }

    public function testItPredictsALockoutBeforeARuleIsAdded(): void
    {
        // Arrange
        $policy = new IpPolicy($this->rules([], []));

        // Act
        $result = $policy->allowsAfterAdding('allow', '203.0.113.0/24', '8.8.8.8');

        // Assert
        $this->assertFalse($result);
    }

    /**
     * @param string[] $allow
     * @param string[] $deny
     */
    private function rules(array $allow, array $deny): IpRules
    {
        $rules = $this->createStub(IpRules::class);
        $rules->method('ranges')->willReturnCallback(static fn (string $type): array => $type === 'allow' ? $allow : $deny);

        return $rules;
    }
}
