<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AccountActions;

use PhpSso\Audit\AccountActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AccountActions::class, 'succeeded')]
final class SucceededTest extends TestCase
{
    /** @return array<string, array{string, int, string|null, bool}> */
    public static function cases(): array
    {
        return [
            'a flash action with a success message' => ['flash', 302, 'success', true],
            'a flash action with no message (a bare redirect)' => ['flash', 302, null, false],
            'a flash action with an error' => ['flash', 302, 'error', false],
            'a flash action that failed validation' => ['flash', 422, 'success', false],
            'a page action that showed the page' => ['page', 200, null, true],
            'a page action refused' => ['page', 401, null, false],
            'a page action that only redirected' => ['page', 302, null, false],
        ];
    }

    #[DataProvider('cases')]
    public function testItCountsAChangeOnlyWhenTheResponseSaysItWasMade(string $expect, int $status, ?string $flash, bool $expected): void
    {
        // Arrange
        $plan = ['expect' => $expect];

        // Act
        $succeeded = AccountActions::succeeded($plan, $status, $flash);

        // Assert
        $this->assertSame($expected, $succeeded);
    }
}
