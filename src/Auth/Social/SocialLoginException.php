<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

use RuntimeException;

/** A social sign-in that cannot go on. The message is safe to show people; the detail is for the log. */
final class SocialLoginException extends RuntimeException
{
    public function __construct(string $message, private readonly string $detail = '')
    {
        parent::__construct($message);
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
