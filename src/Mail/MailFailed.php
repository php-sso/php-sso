<?php

declare(strict_types=1);

namespace PhpSso\Mail;

use RuntimeException;

/** A message could not be sent: the mail server could not be reached, refused it, or the setup is wrong. The text never holds a password. */
final class MailFailed extends RuntimeException
{
}
