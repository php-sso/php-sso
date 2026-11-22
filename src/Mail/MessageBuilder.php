<?php

declare(strict_types=1);

namespace PhpSso\Mail;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Turns an {@see Email} into the text of an internet message (RFC 5322 with MIME): every line ends in CRLF, everything is plain ASCII on the wire
 * (non-ASCII in the subject and the names is an encoded word, the bodies are quoted-printable), and an HTML part comes after the text part so that
 * mail programs that can show it prefer it.
 */
final class MessageBuilder
{
    /** @param string $domain what comes after the `@` of the Message-ID: the domain of the sender */
    public function __construct(private string $from, private string $fromName, private string $domain)
    {
        Email::assertAddress($from);
        if (preg_match('/[\r\n\0]/', $fromName) === 1) {
            throw new InvalidArgumentException('The sender name cannot contain a line break.');
        }
    }

    public function build(Email $email, ?DateTimeImmutable $now = null, ?string $boundary = null, ?string $messageId = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $boundary ??= 'sso-' . bin2hex(random_bytes(12));
        $messageId ??= bin2hex(random_bytes(16)) . '@' . $this->domain;

        $headers = [
            'Date: ' . $now->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::mailbox($this->from, $this->fromName),
            'To: ' . self::mailbox($email->to, $email->toName ?? ''),
            'Subject: ' . self::headerText($email->subject),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated', // it is not a person writing: automatic replies (out of office) stay away
            'X-Auto-Response-Suppress: All',
        ];

        if ($email->html === null) {
            return implode("\r\n", [...$headers, ...self::part('text/plain', $email->text)]) . "\r\n";
        }

        return implode("\r\n", [
            ...$headers,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            ...self::part('text/plain', $email->text),
            '--' . $boundary,
            ...self::part('text/html', $email->html),
            '--' . $boundary . '--',
        ]) . "\r\n";
    }

    /** @return list<string> the headers of one body part, a blank line, and its quoted-printable body */
    private static function part(string $type, string $body): array
    {
        // Real CRLF line breaks go in: the encoder keeps those as line breaks, and would write a lone LF as =0A.
        $body = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));

        return ['Content-Type: ' . $type . '; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable', '', rtrim(quoted_printable_encode($body), "\r\n")];
    }

    /** `Name <address>`, with the name as a quoted string or, when it is not plain ASCII, as an encoded word. */
    private static function mailbox(string $address, string $name): string
    {
        if ($name === '') {
            return $address;
        }

        return (preg_match('/^[\x20-\x7e]+$/', $name) === 1 ? '"' . addcslashes($name, '"\\') . '"' : self::headerText($name)) . ' <' . $address . '>';
    }

    /** Header text: as it is when it is plain ASCII, otherwise RFC 2047 encoded words of at most 75 characters (cut between characters, not inside one). */
    private static function headerText(string $text): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $text) === 1) {
            return $text;
        }

        $words = [];
        $current = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            if (strlen(base64_encode($current . $character)) > 45) { // 45 characters of base64 plus the 12 of "=?UTF-8?B?" and "?=" stay well under 75
                $words[] = $current;
                $current = '';
            }
            $current .= $character;
        }
        $words[] = $current;

        return implode("\r\n ", array_map(static fn (string $word): string => '=?UTF-8?B?' . base64_encode($word) . '?=', $words));
    }
}
