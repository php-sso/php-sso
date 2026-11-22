<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\AdminActions;

use PhpSso\Audit\AdminActions;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(AdminActions::class, 'succeeded')]
final class SucceededTest extends TestCase
{
    /** @return array<string, array{int, string|null, bool}> */
    public static function cases(): array
    {
        return [
            'a redirect after a change' => [302, 'success', true],
            'a redirect with no message' => [302, null, true],
            'a page shown after a change (a new secret)' => [200, null, true],
            'a refusal (an error message)' => [302, 'error', false],
            'a form with mistakes' => [422, null, false],
            'something that was not found' => [404, null, false],
            'a bad token' => [400, null, false],
        ];
    }

    #[DataProvider('cases')]
    public function testItIsASuccessOnlyWhenTheChangeWasMade(int $status, ?string $flash, bool $expected): void
    {
        // Arrange (the case)

        // Act
        $succeeded = AdminActions::succeeded($status, $flash);

        // Assert
        $this->assertSame($expected, $succeeded);
    }
}
