<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Identifier\EmailResolver;

use PhpSso\Auth\Identifier\EmailResolver;
use PhpSso\Store\Users;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(EmailResolver::class, 'normalize')]
final class NormalizeTest extends TestCase
{
    public function testItTrimsAndLowerCasesTheAddress(): void
    {
        // Arrange
        $resolver = new EmailResolver((new TestData())->users);

        // Act
        $normalized = $resolver->normalize("  Alice@Example.COM \n");

        // Assert
        $this->assertSame('alice@example.com', $normalized);
    }
}
