<?php

declare(strict_types=1);

namespace PhpSso\Auth\Social;

/** An outside service people can sign in with. */
interface SocialProvider
{
    /** Short id used in addresses and the database, such as `google`. */
    public function key(): string;

    /** Name shown to people, such as `Google`. */
    public function label(): string;

    /** Where to send the browser so the person can approve the sign-in. @throws SocialLoginException when the provider cannot be reached */
    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeChallenge): string;

    /**
     * Trades the code the provider sent back for the person's identity.
     *
     * @throws SocialLoginException when the provider refuses, or what it returns cannot be trusted
     */
    public function identify(string $redirectUri, string $code, string $codeVerifier, string $nonce): ExternalIdentity;
}
