<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\OidcProvider;

use PhpSso\Auth\Social\OidcProvider;
use PhpSso\Auth\Social\SocialLoginException;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversMethod(OidcProvider::class, 'identify')]
final class IdentifyTest extends TestCase
{
    public function testItTradesTheCodeForTheIdentityInTheIdToken(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->idToken());

        // Act
        $identity = $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert
        $this->assertSame(['idp', 'sub-42', 'alice@example.com', true, 'Alice Example'], [$identity->provider, $identity->subject, $identity->email, $identity->emailVerified, $identity->name]);
    }

    public function testItSendsTheCodeThePkceVerifierAndTheClientCredentialsToTheTokenEndpoint(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->idToken());

        // Act
        $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert
        $form = $idp->http->lastForm('https://idp.example.com/token');
        $this->assertSame(['authorization_code', 'code-1', Fixture::REDIRECT, Fixture::CLIENT_ID, 'secret-xyz', 'verifier-1'], [$form['grant_type'], $form['code'], $form['redirect_uri'], $form['client_id'], $form['client_secret'], $form['code_verifier']]);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> claim changes, signed with, why it must fail */
    public static function bad(): array
    {
        return [
            'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'main', 'has expired'],
            'not valid yet' => [['nbf' => time() + 3600], 'main', 'not valid yet'],
            'issued in the future' => [['iat' => time() + 3600], 'main', 'issued in the future'],
            'no expiry' => [['exp' => null], 'main', 'no expiry'],
            'wrong issuer' => [['iss' => 'https://evil.example.com'], 'main', 'wrong issuer'],
            'meant for another client' => [['aud' => 'someone-else'], 'main', 'not meant for this client'],
            'two audiences and the wrong authorized party' => [['aud' => [Fixture::CLIENT_ID, 'other'], 'azp' => 'other'], 'main', 'wrong authorized party'],
            'no nonce' => [['nonce' => null], 'main', 'wrong nonce'],
            'no subject' => [['sub' => null], 'main', 'no subject'],
            'signed with someone else\'s key' => [[], 'other', 'signature does not match'],
        ];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('bad')]
    public function testItRefusesAnIdTokenThatCannotBeTrusted(array $claims, string $signedWith, string $why): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->idToken($claims, 'nonce-1', $signedWith));

        // Act
        $failure = null;
        try {
            $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');
        } catch (SocialLoginException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertNotNull($failure, 'the token must be refused');
        $this->assertStringContainsStringIgnoringCase($why, $failure->detail());
    }

    public function testItRefusesATokenForADifferentNonce(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->idToken([], 'a-replayed-nonce'));
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert (the exception above)
    }

    public function testItRefusesATokenThatClaimsHs256WithThePublicKeyAsSecret(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->confusedToken());
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert (the exception above)
    }

    public function testItRefusesATokenThatSaysItNeedsNoSignature(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->unsignedToken());

        // Act
        $failure = null;
        try {
            $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');
        } catch (SocialLoginException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertNotNull($failure);
        $this->assertStringContainsString('The ID token was refused', $failure->detail());
    }

    public function testItRefusesANoneTokenEvenWithASignatureMadeUp(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->unsignedToken('AAAA'));

        // Act
        $failure = null;
        try {
            $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');
        } catch (SocialLoginException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertNotNull($failure);
        $this->assertStringContainsString('Only RS256', $failure->detail());
    }

    public function testItRefusesATokenThatDoesNotNameItsKey(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->tokenWithoutKeyId());

        // Act
        $failure = null;
        try {
            $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');
        } catch (SocialLoginException $e) {
            $failure = $e;
        }

        // Assert
        $this->assertNotNull($failure);
        $this->assertStringContainsString('does not name its signing key', $failure->detail());
    }

    public function testItRefusesATokenResponseWithoutAnIdToken(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->http->routes['https://idp.example.com/token'] = [200, ['access_token' => 'at']];
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert (the exception above)
    }

    public function testItRefusesWhenTheTokenEndpointRejectsTheCode(): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->http->routes['https://idp.example.com/token'] = [400, ['error' => 'invalid_grant']];
        $this->expectException(SocialLoginException::class);

        // Act
        $idp->provider->identify(Fixture::REDIRECT, 'bad-code', 'verifier-1', 'nonce-1');

        // Assert (the exception above)
    }

    /** @return array<string, array{mixed, bool}> */
    public static function verifiedValues(): array
    {
        return [
            'true' => [true, true],
            'the text true' => ['true', true],
            'false' => [false, false],
            'the text false' => ['false', false],
            'missing' => [null, false],
        ];
    }

    #[DataProvider('verifiedValues')]
    public function testItReadsTheEmailVerifiedClaimStrictly(mixed $claim, bool $expected): void
    {
        // Arrange
        $idp = new Fixture();
        $idp->tokenEndpointReturns($idp->idToken(['email_verified' => $claim]));

        // Act
        $identity = $idp->provider->identify(Fixture::REDIRECT, 'code-1', 'verifier-1', 'nonce-1');

        // Assert
        $this->assertSame($expected, $identity->emailVerified);
    }
}
