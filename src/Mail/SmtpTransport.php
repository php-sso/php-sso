<?php

declare(strict_types=1);

namespace PhpSso\Mail;

/** One connection to a mail server, as far as {@see SmtpMailer} needs it. Tests give it a script instead of a socket. */
interface SmtpTransport
{
    /**
     * The next line the server sent, without its line break.
     *
     * @throws MailFailed when the server hangs up or is too slow
     */
    public function readLine(): string;

    /** @throws MailFailed */
    public function write(string $data): void;

    /**
     * Wraps the connection in TLS, after the server agreed to STARTTLS. The certificate must be valid for the server's name.
     *
     * @throws MailFailed when it cannot be secured: the connection is not used unencrypted instead
     */
    public function startTls(): void;

    public function close(): void;
}
