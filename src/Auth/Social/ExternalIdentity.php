<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

/** Who a provider says someone is. `subject` is the provider's permanent id for them: never match people on the email. */
final class ExternalIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {
    }

    /** The email address, only when the provider vouches for it. */
    public function verifiedEmail(): ?string
    {
        return $this->emailVerified && $this->email !== null && $this->email !== '' ? mb_strtolower($this->email) : null;
    }
}
