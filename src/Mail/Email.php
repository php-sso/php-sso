<?php

declare(strict_types=1);

namespace PhpSso\Mail;

use InvalidArgumentException;

/** One message to one person: plain text, and optionally the same in HTML. What goes into the headers is checked here, once, so no later step has to. */
final readonly class Email
{
    public string $subject;

    public function __construct(public string $to, string $subject, public string $text, public ?string $html = null, public ?string $toName = null)
    {
        self::assertAddress($to);
        self::assertHeaderText($subject, 'subject');
        if ($toName !== null) {
            self::assertHeaderText($toName, 'name');
        }
        $this->subject = trim($subject);
        if (trim($text) === '') {
            throw new InvalidArgumentException('A message needs some text.');
        }
    }

    /** An address that can go in a header and an SMTP command as it is: no line break, no angle brackets, no quotes, no spaces. */
    public static function assertAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\0<>"\s,;]/', $address) === 1 || strlen($address) > 254) {
            throw new InvalidArgumentException('That is not an email address that can be used.');
        }
    }

    /** Text for a header (a subject, a name): one line, nothing that could start another header. */
    private static function assertHeaderText(string $text, string $what): void
    {
        if (preg_match('/[\r\n\0]/', $text) === 1) {
            throw new InvalidArgumentException("The {$what} of a message cannot contain a line break.");
        }
    }
}
