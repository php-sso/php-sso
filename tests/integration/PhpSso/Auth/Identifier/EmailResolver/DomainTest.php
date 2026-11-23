<?php

declare(strict_types=1);

namespace Tests\integration\PhpSso\Auth\Identifier\EmailResolver;

use PhpSso\Auth\Identifier\EmailResolver;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\integration\PhpSso\Support\TestData;

#[CoversMethod(EmailResolver::class, 'domain')]
final class DomainTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function cases(): array
    {
        return [
            'plain address' => ['alice@acme.com', 'acme.com'],
            'upper case and spaces' => [' Alice@ACME.com ', 'acme.com'],
            'no at sign' => ['alice', null],
            'nothing after the at sign' => ['alice@', null],
            'nothing before the at sign' => ['@acme.com', null],
        ];
    }

    #[DataProvider('cases')]
    public function testItReturnsTheDomainOfTheAddress(string $input, ?string $expected): void
    {
        // Arrange
        $resolver = new EmailResolver((new TestData())->users);

        // Act
        $domain = $resolver->domain($input);

        // Assert
        $this->assertSame($expected, $domain);
    }
}
