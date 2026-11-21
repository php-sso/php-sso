<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\OidcProvider;

use PhpSso\Auth\Social\OidcProvider;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\Store\HttpCache;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\TestData;

#[CoversMethod(OidcProvider::class, 'identify')]
final class CachingTest extends TestCase
{
    private TestData $data;
    private HttpCache $cache;

    protected function setUp(): void
    {
        $this->data = new TestData();
        $this->cache = new HttpCache($this->data->db);
    }

    /** One sign-in, as a new request would do it: a fresh provider object sharing the saved answers. */
    private function signIn(?Fixture $fixture = null, string $signedWith = 'main'): Fixture
    {
        $fixture ??= new Fixture(cache: $this->cache);
        $fixture->tokenEndpointReturns($fixture->idToken(signedWith: $signedWith));
        $fixture->provider->identify(Fixture::REDIRECT, 'code', 'verifier', 'nonce-1');

        return $fixture;
    }

    public function testTheSecondSignInDoesNotFetchTheDiscoveryDocumentOrTheKeys(): void
    {
        // Arrange
        $first = $this->signIn();

        // Act
        $second = $this->signIn();

        // Assert
        $this->assertSame(['discovery' => 1, 'keys' => 1], $first->fetches());
        $this->assertSame(['discovery' => 0, 'keys' => 0], $second->fetches());
    }

    public function testWithoutACacheEverySignInFetchesThem(): void
    {
        // Arrange
        $first = new Fixture();
        $second = new Fixture();

        // Act
        $this->signIn($first);
        $this->signIn($second);

        // Assert
        $this->assertSame(['discovery' => 1, 'keys' => 1], $second->fetches());
    }

    public function testSavedAnswersOlderThanAnHourAreFetchedAgain(): void
    {
        // Arrange
        $this->signIn();
        $this->data->db->pdo->exec('UPDATE http_cache SET fetched_at = fetched_at - 7200');

        // Act
        $again = $this->signIn();

        // Assert
        $this->assertSame(['discovery' => 1, 'keys' => 1], $again->fetches());
    }

    public function testAProviderThatChangedItsKeysIsFollowedWithOneNewFetch(): void
    {
        // Arrange
        $fixture = new Fixture(cache: $this->cache);
        $this->cache->put('oidc-jwks:' . Fixture::ISSUER, $fixture->outdatedKeySet(), time() - 600); // saved ten minutes ago, before the change

        // Act
        $this->signIn($fixture);

        // Assert
        $this->assertSame(1, $fixture->fetches()['keys']);
        $this->assertNotSame($fixture->outdatedKeySet(), $this->cache->entry('oidc-jwks:' . Fixture::ISSUER)['body']);
    }

    public function testButNotWhenTheKeysWereJustFetched(): void
    {
        // Arrange
        $fixture = new Fixture(cache: $this->cache);
        $this->cache->put('oidc-jwks:' . Fixture::ISSUER, $fixture->outdatedKeySet(), time() - 10);

        // Act
        $attempt = fn () => $this->signIn($fixture);

        // Assert
        $this->expectException(SocialLoginException::class);
        try {
            $attempt();
        } finally {
            $this->assertSame(0, $fixture->fetches()['keys']);
        }
    }

    public function testBadTokensCannotMakeUsFetchTheKeysOverAndOver(): void
    {
        // Arrange
        $this->signIn(); // saves the real keys
        $this->data->db->pdo->exec('UPDATE http_cache SET fetched_at = fetched_at - 600');
        $attempts = [new Fixture(cache: $this->cache), new Fixture(cache: $this->cache), new Fixture(cache: $this->cache)];

        // Act
        foreach ($attempts as $fixture) {
            try {
                $this->signIn($fixture, 'other'); // signed with someone else's key
            } catch (SocialLoginException) {
                // refused, as it should be
            }
        }

        // Assert
        $this->assertSame([1, 0, 0], array_map(static fn (Fixture $f): int => $f->fetches()['keys'], $attempts));
    }

    public function testADiscoveryDocumentThatNamesAnotherIssuerIsRefusedAndNotSaved(): void
    {
        // Arrange
        $fixture = new Fixture(['issuer' => 'https://evil.example.com'], cache: $this->cache);

        // Act
        $attempt = fn () => $this->signIn($fixture);

        // Assert
        $this->expectException(SocialLoginException::class);
        try {
            $attempt();
        } finally {
            $this->assertNull($this->cache->entry('oidc-metadata:' . Fixture::ISSUER));
        }
    }
}
