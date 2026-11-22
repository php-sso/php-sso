<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AdminActions;

use PhpSso\Audit\AdminActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AdminActions::class, 'plan')]
final class PlanTest extends TestCase
{
    /** @return callable(string, string): ?array<string, mixed> */
    private static function rows(array $rows = []): callable
    {
        return static fn (string $type, string $id): ?array => $rows[$type . ':' . $id] ?? null;
    }

    public function testAHandlerThatIsNotRecordedHasNoPlan(): void
    {
        // Arrange
        $find = self::rows();

        // Act
        $plan = AdminActions::plan('dashboard', [], [], $find);

        // Assert
        $this->assertNull($plan);
    }

    public function testAnActionOnSomethingNamesItAndCopiesItsName(): void
    {
        // Arrange
        $find = self::rows(['user:sub-9' => ['username' => 'alice', 'is_admin' => 0]]);

        // Act
        $plan = AdminActions::plan('userLock', ['sub-9'], ['_csrf' => 'x'], $find);

        // Assert
        $this->assertSame(['action' => 'user.lock', 'type' => 'user', 'target' => 'sub-9', 'label' => 'alice', 'detail' => []], $plan);
    }

    public function testACreateNamesWhatIsMadeFromThePostedField(): void
    {
        // Arrange
        $body = ['name' => 'Billing', 'description' => 'x', '_csrf' => 'x'];

        // Act
        $plan = AdminActions::plan('groupCreate', [], $body, self::rows());

        // Assert
        $this->assertSame('group.create', $plan['action']);
        $this->assertNull($plan['target']);
        $this->assertSame('Billing', $plan['label']);
    }

    public function testADeleteKeepsTheNameOfWhatIsGone(): void
    {
        // Arrange
        $find = self::rows(['client:app-1' => ['name' => 'Billing app']]);

        // Act
        $plan = AdminActions::plan('clientDelete', ['app-1'], [], $find);

        // Assert
        $this->assertSame('app-1', $plan['target']);
        $this->assertSame('Billing app', $plan['label']);
    }

    public function testAUserUpdateSaysWhetherAdministratorRightsAreOnNowAndWereBefore(): void
    {
        // Arrange
        $find = self::rows(['user:sub-9' => ['username' => 'alice', 'is_admin' => 0]]);

        // Act
        $plan = AdminActions::plan('userUpdate', ['sub-9'], ['username' => 'alice', 'is_admin' => '1', 'email' => 'a@example.com'], $find);

        // Assert
        $this->assertSame(['is_admin' => true, 'was_admin' => false], $plan['detail']);
    }

    public function testAPasswordOrAnyOtherPostedValueIsNeverKept(): void
    {
        // Arrange
        $body = ['username' => 'bob', 'password' => 'hunter2-hunter2', 'email' => 'bob@example.com', 'secret' => 's3cret', 'is_admin' => '1', '_csrf' => 'x'];

        // Act
        $plan = AdminActions::plan('userCreate', [], $body, self::rows());

        // Assert
        $this->assertSame(['is_admin' => true], $plan['detail']);
        $this->assertStringNotContainsString('hunter2', json_encode($plan));
        $this->assertStringNotContainsString('s3cret', json_encode($plan));
    }

    public function testASettingsFormKeepsTheNamesOfTheFieldsAndNotTheirValues(): void
    {
        // Arrange
        $body = ['_csrf' => 'x', 'max_attempts' => '5', 'window' => '900', 'webhook_secret' => 'topsecret'];

        // Act
        $plan = AdminActions::plan('securitySettings', [], $body, self::rows());

        // Assert
        $this->assertSame(['fields' => ['max_attempts', 'window', 'webhook_secret']], $plan['detail']);
        $this->assertStringNotContainsString('topsecret', json_encode($plan));
    }

    public function testARelationChangeIsAboutTheOwnerAndKeepsTheItem(): void
    {
        // Arrange
        $find = self::rows(['group:5' => ['name' => 'Staff']]);

        // Act
        $plan = AdminActions::plan('relation', ['group-users', '5', 'add'], ['item' => 'sub-9', '_csrf' => 'x'], $find);

        // Assert
        $this->assertSame(['action' => 'relation.add', 'type' => 'group', 'target' => '5', 'label' => 'Staff', 'detail' => ['relation' => 'group-users', 'item' => 'sub-9']], $plan);
    }

    public function testRemovingEveryoneIsItsOwnAction(): void
    {
        // Arrange
        $args = ['role-users', '3', 'remove-all'];

        // Act
        $plan = AdminActions::plan('relation', $args, [], self::rows());

        // Assert
        $this->assertSame('relation.remove_all', $plan['action']);
        $this->assertSame('role', $plan['type']);
    }

    public function testARouteArgumentCanBeKept(): void
    {
        // Arrange
        $find = self::rows(['client:app-1' => ['name' => 'Billing app']]);

        // Act
        $plan = AdminActions::plan('clientIpRuleDelete', ['app-1', '17'], [], $find);

        // Assert
        $this->assertSame('app-1', $plan['target']);
        $this->assertSame(['rule' => '17'], $plan['detail']);
    }

    public function testImpersonatingFromTheAccessTestNamesThePersonFromTheForm(): void
    {
        // Arrange
        $find = self::rows(['user:sub-9' => ['username' => 'alice']]);

        // Act
        $plan = AdminActions::plan('impersonate', [], ['user' => 'sub-9'], $find);

        // Assert
        $this->assertSame('user.impersonate', $plan['action']);
        $this->assertSame('sub-9', $plan['target']);
        $this->assertSame('alice', $plan['label']);
    }
}
