<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Mail\MailFailed;
use PhpSso\Mail\SmtpTransport;

/** A mail server that follows a script: the greeting is there from the start, and every write by the client is answered with the next reply. */
final class FakeSmtp implements SmtpTransport
{
    /** @var list<string> everything the client wrote */
    public array $written = [];
    public bool $tls = false;
    public bool $closed = false;

    /** @var list<string> */
    private array $buffer;

    /** @param list<list<string>> $replies the greeting first, then one reply (its lines) for each write */
    public function __construct(private array $replies, private bool $tlsWorks = true)
    {
        $this->buffer = array_shift($this->replies) ?? [];
    }

    public function readLine(): string
    {
        if ($this->buffer === []) {
            throw new MailFailed('The mail server hung up or took too long to answer.');
        }

        return array_shift($this->buffer);
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
        $this->buffer = [...$this->buffer, ...(array_shift($this->replies) ?? [])];
    }

    public function startTls(): void
    {
        if (!$this->tlsWorks) {
            throw new MailFailed('Cannot secure the connection: its certificate was not accepted.');
        }
        $this->tls = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** @return list<string> the commands (not the message) the client sent, without line breaks */
    public function commands(): array
    {
        return array_values(array_map(static fn (string $w): string => rtrim($w, "\r\n"), array_filter($this->written, static fn (string $w): bool => !str_contains($w, "\r\nSubject") && !str_contains($w, 'Subject:'))));
    }

    /** The message the client sent after DATA. */
    public function message(): string
    {
        foreach ($this->written as $w) {
            if (str_contains($w, 'Subject:')) {
                return $w;
            }
        }

        return '';
    }
}
