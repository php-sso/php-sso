<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Keys;

use PhpSso\Keys;
use PhpSso\OAuthServer\Jwt\InvalidJwt;
use PhpSso\OAuthServer\Jwt\Jwks;
use PhpSso\OAuthServer\Jwt\JwtDecoder;
use PhpSso\OAuthServer\Jwt\JwtEncoder;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\UsesKeyDirectory;

#[CoversMethod(Keys::class, 'rotate')]
#[CoversMethod(Keys::class, '__construct')]
final class RotateTest extends TestCase
{
    use UsesKeyDirectory;

    private const NOW = 1_800_000_000;
    private const KEEP = 86400;

    public function testTheNewKeyIsPublishedAtOnceButOnlySignsAfterTheDelay(): void
    {
        // Arrange
        $oldKid = (new Keys($this->dir, self::KEEP, self::NOW))->provider->signingKey()->kid;

        // Act
        $new = (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);
        $before = new Keys($this->dir, self::KEEP, self::NOW + 3599);

        // Assert
        $this->assertSame($oldKid, $before->provider->signingKey()->kid);
        $this->assertEqualsCanonicalizing([$oldKid, $new['kid']], $this->publishedKids($before));
        $this->assertSame(self::NOW + 3600, $new['activeFrom']);
    }

    public function testTheNewKeySignsFromItsTimeAndTheOldOneStaysPublished(): void
    {
        // Arrange
        $oldKid = (new Keys($this->dir, self::KEEP, self::NOW))->provider->signingKey()->kid;
        $new = (new Keys($this->dir, self::KEEP, self::NOW))->rotate(3600);

        // Act
        $after = new Keys($this->dir, self::KEEP, self::NOW + 3600);

        // Assert
        $this->assertSame($new['kid'], $after->provider->signingKey()->kid);
        $this->assertSame([$new['kid'], $oldKid], $this->publishedKids($after));
    }

    public function testARotationWithNoDelayTakesEffectAtOnce(): void
    {
        // Arrange
        $keys = new Keys($this->dir, self::KEEP, self::NOW);

        // Act
        $new = $keys->rotate(0);
        $after = new Keys($this->dir, self::KEEP, self::NOW);

        // Assert
        $this->assertSame($new['kid'], $after->provider->signingKey()->kid);
    }

    public function testASecondRotationIsRefusedWhileOneIsWaiting(): void
    {
        // Arrange
        $keys = new Keys($this->dir, self::KEEP, self::NOW);
        $keys->rotate(3600);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already waiting');

        // Act
        $keys->rotate(3600);

        // Assert (the exception above)
    }

    public function testThePrivateKeyOfTheNewKeyIsReadableOnlyByTheOwner(): void
    {
        // Arrange
        $keys = new Keys($this->dir, self::KEEP, self::NOW);

        // Act
        $new = $keys->rotate(60);

        // Assert
        $private = glob($this->dir . '/ring/*-' . $new['kid'] . '.key');
        $this->assertCount(1, $private);
        $this->assertSame(0600, fileperms($private[0]) & 0777);
        $this->assertSame(0700, fileperms($this->dir . '/ring') & 0777);
    }

    public function testTheRetiredKeyKeepsItsPublicKeyInTheJwks(): void
    {
        // Arrange
        $first = new Keys($this->dir, self::KEEP, self::NOW);
        $oldJwk = $first->provider->signingKey()->verificationKey()->toJwk();

        // Act
        $first->rotate(0);
        $after = new Keys($this->dir, self::KEEP, self::NOW);

        // Assert
        $jwks = array_map(static fn ($key): array => $key->toJwk(), $after->provider->verificationKeys());
        $this->assertContains($oldJwk, $jwks);
    }

    public function testATokenSignedBeforeTheRotationStillVerifiesAfterIt(): void
    {
        // Arrange
        $token = (new JwtEncoder((new Keys($this->dir, self::KEEP, self::NOW))->provider))->encode(['sub' => 'alice']);
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(60);
        $after = new Keys($this->dir, self::KEEP, self::NOW + 120);

        // Act
        $decoded = (new JwtDecoder($after->provider))->decode($token);

        // Assert
        $this->assertSame('alice', $decoded['claims']['sub']);
    }

    public function testNewTokensAreSignedWithTheNewKeyAfterTheDelay(): void
    {
        // Arrange
        $new = (new Keys($this->dir, self::KEEP, self::NOW))->rotate(60);
        $after = new Keys($this->dir, self::KEEP, self::NOW + 60);

        // Act
        $token = (new JwtEncoder($after->provider))->encode(['sub' => 'alice']);

        // Assert
        $header = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
        $this->assertSame($new['kid'], $header['kid']);
    }

    public function testATokenOfAnExpiredPrunedKeyNoLongerVerifies(): void
    {
        // Arrange
        $token = (new JwtEncoder((new Keys($this->dir, self::KEEP, self::NOW))->provider))->encode(['sub' => 'alice']);
        (new Keys($this->dir, self::KEEP, self::NOW))->rotate(60);
        $end = self::NOW + 60 + self::KEEP;
        (new Keys($this->dir, self::KEEP, $end))->prune();
        $this->expectException(InvalidJwt::class);

        // Act
        (new JwtDecoder((new Keys($this->dir, self::KEEP, $end))->provider))->decode($token);

        // Assert (the exception above)
    }

    public function testTheJwksPublishesTheNewAndTheRetiredKey(): void
    {
        // Arrange
        $oldKid = (new Keys($this->dir, self::KEEP, self::NOW))->provider->signingKey()->kid;
        $new = (new Keys($this->dir, self::KEEP, self::NOW))->rotate(60);

        // Act
        $jwks = Jwks::toArray((new Keys($this->dir, self::KEEP, self::NOW + 60))->provider);

        // Assert
        $this->assertEqualsCanonicalizing([$oldKid, $new['kid']], array_column($jwks['keys'], 'kid'));
    }
}
