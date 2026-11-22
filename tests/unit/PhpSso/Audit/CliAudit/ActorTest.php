<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Audit\CliAudit;

use PhpSso\Audit\CliAudit;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(CliAudit::class, 'actor')]
final class ActorTest extends TestCase
{
    public function testItNamesWhoRanTheCommandAsSomethingThatIsNotAPerson(): void
    {
        // Arrange (whoever runs the tests)

        // Act
        $actor = CliAudit::actor();

        // Assert
        $this->assertMatchesRegularExpression('/^cli:\S+$/', $actor);
    }
}
