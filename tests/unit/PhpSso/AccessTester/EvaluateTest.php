<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\AccessTester;

use PhpSso\AccessTester;
use PhpSso\IpPolicy;
use PhpSso\Store\IpRules;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(AccessTester::class, 'evaluate')]
final class EvaluateTest extends TestCase
{
    private TestData $data;
    private IpRules $rules;
    private AccessTester $tester;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->rules = new IpRules($this->data->db);
        $this->tester = new AccessTester(new IpPolicy($this->rules), $this->data->clients, $this->data->users);
    }

    public function testItSkipsClientChecksWhenNoClientIsChosen(): void
    {
        // Arrange: no rules, no client

        // Act
        $result = $this->tester->evaluate('8.8.8.8', null, null);

        // Assert
        $this->assertTrue($result['allowed']);
        $this->assertSame(['pass', 'skipped', 'skipped'], array_column($result['steps'], 'status'));
    }

    public function testAGeneralDenyRuleBlocksEverything(): void
    {
        // Arrange
        $this->data->client('app');
        $sub = $this->data->user('alice');
        $this->rules->create('deny', '198.51.100.0/24', '');

        // Act
        $result = $this->tester->evaluate('198.51.100.5', 'app', $sub);

        // Assert
        $this->assertFalse($result['allowed']);
        $this->assertSame(['fail', 'pass', 'pass'], array_column($result['steps'], 'status'));
    }

    public function testAClientRuleBlocksOnlyThatClient(): void
    {
        // Arrange
        $this->data->client('app');
        $this->data->client('other');
        $sub = $this->data->user('alice');
        $this->rules->create('deny', '198.51.100.0/24', '', 'app');

        // Act
        $blocked = $this->tester->evaluate('198.51.100.5', 'app', $sub);
        $allowed = $this->tester->evaluate('198.51.100.5', 'other', $sub);

        // Assert
        $this->assertSame([false, true], [$blocked['allowed'], $allowed['allowed']]);
    }

    public function testARestrictedClientBlocksAPersonWithoutAccess(): void
    {
        // Arrange
        $this->data->client('app', restricted: true);
        $sub = $this->data->user('alice');

        // Act
        $result = $this->tester->evaluate('8.8.8.8', 'app', $sub);

        // Assert
        $this->assertFalse($result['allowed']);
        $this->assertSame('fail', $result['steps'][2]['status']);
    }

    public function testAClientThatIgnoresTheGeneralRulesSkipsTheGeneralStep(): void
    {
        // Arrange
        $this->data->client('app');
        $sub = $this->data->user('alice');
        $this->rules->create('deny', '198.51.100.0/24', '');
        $this->rules->setIgnoresGeneral('app', true);

        // Act
        $result = $this->tester->evaluate('198.51.100.5', 'app', $sub);

        // Assert
        $this->assertTrue($result['allowed']);
        $this->assertSame(['skipped', 'pass', 'pass'], array_column($result['steps'], 'status'));
    }
}
