<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Identifier\UsernameResolver;

use PhpSso\Auth\Identifier\UsernameResolver;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(UsernameResolver::class, 'domain')]
final class DomainTest extends TestCase
{
    public function testItHasNoDomainEvenForAnAddress(): void
    {
        // Arrange
        $resolver = new UsernameResolver((new TestData())->users);

        // Act
        $domain = $resolver->domain('alice@acme.com');

        // Assert
        $this->assertNull($domain);
    }
}
