<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\SocialLogin;

use PhpSso\Auth\Social\ExternalIdentity;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\Auth\Social\SocialProvider;

/** A provider that hands back whatever identity the test sets, and remembers what it was asked. */
final class FakeProvider implements SocialProvider
{
    public ?ExternalIdentity $identity = null;
    public ?SocialLoginException $failure = null;
    /** @var array<string, string> */
    public array $lastAuthorization = [];
    /** @var list<array<string, string>> */
    public array $identifyCalls = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $codeChallenge): string
    {
        $this->lastAuthorization = ['redirect_uri' => $redirectUri, 'state' => $state, 'nonce' => $nonce, 'code_challenge' => $codeChallenge];

        return 'https://idp.test/authorize?state=' . $state;
    }

    public function identify(string $redirectUri, string $code, string $codeVerifier, string $nonce): ExternalIdentity
    {
        $this->identifyCalls[] = ['redirect_uri' => $redirectUri, 'code' => $code, 'verifier' => $codeVerifier, 'nonce' => $nonce];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->identity ?? new ExternalIdentity('fake', 'sub-1', 'alice@example.com', true, 'Alice');
    }
}
