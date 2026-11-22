<?php

declare(strict_types=1);

namespace PhpSso\Mail;

/** Sends a message. How is up to the implementation (an SMTP server today). */
interface Mailer
{
    /** @throws MailFailed when it could not be sent */
    public function send(Email $email): void;
}
