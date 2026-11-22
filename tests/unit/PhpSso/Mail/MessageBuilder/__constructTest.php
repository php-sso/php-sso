<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Mail\MessageBuilder;

use InvalidArgumentException;
use PhpSso\Mail\MessageBuilder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(MessageBuilder::class, '__construct')]
final class __constructTest extends TestCase
{
    public function testASenderAddressThatIsNotAnAddressIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new MessageBuilder("sso@example.com\r\nBcc: x@example.com", 'SSO', 'example.com');

        // Assert (the exception above)
    }

    public function testASenderNameWithALineBreakIsRefused(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        new MessageBuilder('sso@example.com', "SSO\nBcc: x@example.com", 'example.com');

        // Assert (the exception above)
    }
}
