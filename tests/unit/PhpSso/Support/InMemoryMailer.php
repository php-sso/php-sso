<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Support;

use PhpSso\Mail\Email;
use PhpSso\Mail\MailFailed;
use PhpSso\Mail\Mailer;

/** Keeps what it is asked to send (or fails, to see what the caller does about it). */
final class InMemoryMailer implements Mailer
{
    /** @var list<Email> */
    public array $sent = [];

    public function __construct(private bool $failing = false)
    {
    }

    public function send(Email $email): void
    {
        if ($this->failing) {
            throw new MailFailed('The mail server refused it.');
        }
        $this->sent[] = $email;
    }
}
