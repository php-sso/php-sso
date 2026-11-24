<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\IpPolicy;

use PhpSso\IpPolicy;
use PhpSso\Store\IpRules;
use PhpSso\Store\Sql\SqlIpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(IpPolicy::class, 'allowsUser')]
final class AllowsUserTest extends TestCase
{
    private TestData $data;
    private IpRules $rules;
    private IpPolicy $policy;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->rules = new SqlIpRules($this->data->db);
        $this->policy = new IpPolicy($this->rules);
    }

    public function testAPersonWithoutRulesMayUseAnyAddress(): void
    {
        // Arrange
        $sub = $this->data->user('alice');

        // Act
        $result = $this->policy->allowsUser('8.8.8.8', $sub);

        // Assert
        $this->assertTrue($result);
    }

    public function testAnAllowRuleLimitsThePersonToThatRange(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->rules->createForUser('allow', '203.0.113.0/24', '', $sub);

        // Act
        $inside = $this->policy->allowsUser('203.0.113.9', $sub);
        $outside = $this->policy->allowsUser('8.8.8.8', $sub);

        // Assert
        $this->assertSame([true, false], [$inside, $outside]);
    }

    public function testADenyRuleWinsOverAnAllowRule(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->rules->createForUser('allow', '203.0.113.0/24', '', $sub);
        $this->rules->createForUser('deny', '203.0.113.9', '', $sub);

        // Act
        $result = $this->policy->allowsUser('203.0.113.9', $sub);

        // Assert
        $this->assertFalse($result);
    }

    public function testARuleOfOnePersonDoesNotAffectAnother(): void
    {
        // Arrange
        $alice = $this->data->user('alice');
        $bob = $this->data->user('bob');
        $this->rules->createForUser('deny', '198.51.100.0/24', '', $alice);

        // Act
        $result = $this->policy->allowsUser('198.51.100.5', $bob);

        // Assert
        $this->assertTrue($result);
    }

    public function testARuleOfAPersonIsNotAGeneralRule(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->rules->createForUser('deny', '198.51.100.0/24', '', $sub);

        // Act
        $result = $this->policy->allows('198.51.100.5');

        // Assert
        $this->assertTrue($result);
    }

    public function testTheRulesGoWhenThePersonIsDeleted(): void
    {
        // Arrange
        $sub = $this->data->user('alice');
        $this->rules->createForUser('deny', '198.51.100.0/24', '', $sub);

        // Act
        $this->data->users->delete($sub);

        // Assert
        $this->assertSame([], $this->rules->allForUser($sub));
    }
}
