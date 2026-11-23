<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Social\AccountPolicy;

use PhpSso\Auth\AccountPolicy;
use PhpSso\Store\Settings;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;
use PhpSso\Store\Sql\SqlSettings;

#[CoversMethod(AccountPolicy::class, 'current')]
#[CoversMethod(AccountPolicy::class, 'parseForm')]
#[CoversMethod(AccountPolicy::class, 'save')]
#[CoversMethod(AccountPolicy::class, 'domainAllowed')]
#[CoversMethod(AccountPolicy::class, 'form')]
final class PolicyTest extends TestCase
{
    private function policy(array $defaults = []): AccountPolicy
    {
        return new AccountPolicy(new SqlSettings((new TestData())->db), $defaults);
    }

    public function testBothWaysInAreOffByDefault(): void
    {
        // Arrange
        $policy = $this->policy();

        // Act
        $current = $policy->current();

        // Assert
        $this->assertSame(['link_by_verified_email' => false, 'create_users' => false, 'allowed_domains' => []], $current);
    }

    public function testTheConfigValuesApplyUntilSomethingIsSavedAndThenTheSavedOnesWin(): void
    {
        // Arrange
        $policy = $this->policy(['create_users' => true, 'allowed_domains' => ['Corp.Example']]);
        $before = $policy->current();

        // Act
        $policy->save(['link_by_verified_email' => true, 'create_users' => false, 'allowed_domains' => "a.example\nB.example"]);

        // Assert
        $this->assertSame([true, ['corp.example']], [$before['create_users'], $before['allowed_domains']]);
        $this->assertSame(['link_by_verified_email' => true, 'create_users' => false, 'allowed_domains' => ['a.example', 'b.example']], $policy->current());
        $this->assertSame("a.example\nb.example", $policy->form()['allowed_domains']);
    }

    public function testEveryDomainIsAllowedWhenTheListIsEmptyAndOtherwiseOnlyListedOnes(): void
    {
        // Arrange
        $open = $this->policy();
        $limited = $this->policy(['allowed_domains' => ['corp.example']]);

        // Act
        $results = [$open->domainAllowed('x@anywhere.org'), $limited->domainAllowed('x@Corp.Example'), $limited->domainAllowed('x@evil.org'), $limited->domainAllowed('x@sub.corp.example'), $limited->domainAllowed('no-at-sign')];

        // Assert
        $this->assertSame([true, true, false, false, false], $results);
    }

    public function testTheFormAcceptsDomainsSeparatedByLinesCommasOrSpaces(): void
    {
        // Arrange
        $body = ['link_by_verified_email' => '1', 'allowed_domains' => "one.example, two.example\nthree.example"];

        // Act
        [$values, $errors] = AccountPolicy::parseForm($body);

        // Assert
        $this->assertSame([], $errors);
        $this->assertSame([true, false], [$values['link_by_verified_email'], $values['create_users']]);
    }

    public function testTheFormRejectsSomethingThatIsNotADomainAndKeepsWhatWasTyped(): void
    {
        // Arrange
        $body = ['allowed_domains' => "good.example\nnot a domain!\nx"];

        // Act
        [$values, $errors] = AccountPolicy::parseForm($body);

        // Assert
        $this->assertArrayHasKey('allowed_domains', $errors);
        $this->assertSame("good.example\nnot a domain!\nx", $values['allowed_domains']);
    }
}
