<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\Email;

use InvalidArgumentException;
use PhpSso\Mail\Email;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Email::class, '__construct')]
final class __constructTest extends TestCase
{
    public function testAnOrdinaryMessageIsAccepted(): void
    {
        // Arrange (nothing)

        // Act
        $email = new Email('alice@example.com', '  Reset your password ', 'Hello', '<p>Hello</p>', 'Alice');

        // Assert
        $this->assertSame('Reset your password', $email->subject);
        $this->assertSame('alice@example.com', $email->to);
    }

    /** @return array<string, array{string}> */
    public static function badAddresses(): array
    {
        return [
            'not an address' => ['alice'],
            'a line break that would start another header' => ["alice@example.com\r\nBcc: mallory@example.com"],
            'angle brackets' => ['<alice@example.com>'],
            'a second recipient' => ['alice@example.com,mallory@example.com'],
            'a space' => ['alice @example.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('badAddresses')]
    public function testAnAddressThatCouldChangeTheMessageIsRefused(string $address): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Email($address, 'Subject', 'Text');

        // Assert (the exception above)
    }

    public function testALineBreakInTheSubjectIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Email('alice@example.com', "Hello\r\nBcc: mallory@example.com", 'Text');

        // Assert (the exception above)
    }

    public function testALineBreakInTheNameIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Email('alice@example.com', 'Subject', 'Text', null, "Alice\nBcc: x@example.com");

        // Assert (the exception above)
    }

    public function testAMessageWithoutTextIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new Email('alice@example.com', 'Subject', '   ');

        // Assert (the exception above)
    }
}
