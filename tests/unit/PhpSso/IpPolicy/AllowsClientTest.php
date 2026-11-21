<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\IpPolicy;

use PhpSso\IpPolicy;
use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(IpPolicy::class, 'allowsClient')]
final class AllowsClientTest extends TestCase
{
    private IpRules $rules;
    private IpPolicy $policy;

    protected function setUp(): void
    {
        $data = new TestData();
        $data->client('app');
        $this->rules = new IpRules($data->db);
        $this->policy = new IpPolicy($this->rules);
    }

    public function testTheGeneralRulesAlsoApplyToAClient(): void
    {
        // Arrange
        $this->rules->create('allow', '203.0.113.0/24', '');

        // Act
        $result = $this->policy->allowsClient('8.8.8.8', 'app');

        // Assert
        $this->assertFalse($result);
    }

    public function testAClientThatIgnoresTheGeneralRulesIsOpenWhenItHasNoRules(): void
    {
        // Arrange
        $this->rules->create('allow', '203.0.113.0/24', '');
        $this->rules->setIgnoresGeneral('app', true);

        // Act
        $result = $this->policy->allowsClient('8.8.8.8', 'app');

        // Assert
        $this->assertTrue($result);
    }

    public function testAClientThatIgnoresTheGeneralRulesStillFollowsItsOwnRules(): void
    {
        // Arrange
        $this->rules->create('allow', '203.0.113.0/24', '');
        $this->rules->create('deny', '198.51.100.0/24', '', 'app');
        $this->rules->setIgnoresGeneral('app', true);

        // Act
        $result = $this->policy->allowsClient('198.51.100.5', 'app');

        // Assert
        $this->assertFalse($result);
    }

    public function testAClientThatIgnoresTheGeneralRulesIsNotBlockedByGeneralDenyRules(): void
    {
        // Arrange
        $this->rules->create('deny', '198.51.100.0/24', '');
        $this->rules->setIgnoresGeneral('app', true);

        // Act
        $result = $this->policy->allowsClient('198.51.100.5', 'app');

        // Assert
        $this->assertTrue($result);
    }
}
