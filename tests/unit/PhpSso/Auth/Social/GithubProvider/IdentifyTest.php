<?php

declare(strict_types=1);

namespace Tests\unit\PhpSso\Auth\Social\GithubProvider;

use PhpSso\Auth\Social\GithubProvider;
use PhpSso\Auth\Social\HttpJson;
use PhpSso\Auth\Social\SocialLoginException;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Tests\unit\PhpSso\Support\FakeHttpClient;

#[CoversMethod(GithubProvider::class, 'identify')]
#[CoversMethod(GithubProvider::class, 'authorizationUrl')]
final class IdentifyTest extends TestCase
{
    private const REDIRECT = 'https://sso.example.com/login/social/github/callback';

    /** @param array<string, array{int, mixed}> $routes */
    private function github(array $routes): array
    {
        $http = new FakeHttpClient($routes + [
            'https://github.com/login/oauth/access_token' => [200, ['access_token' => 'gho_abc']],
            'https://api.github.com/user' => [200, ['id' => 583231, 'login' => 'octocat', 'name' => 'The Octocat']],
            'https://api.github.com/user/emails' => [200, [
                ['email' => 'old@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'Octo@Example.com', 'primary' => true, 'verified' => true],
            ]],
        ]);

        return [new GithubProvider('gh-client', 'gh-secret', new HttpJson($http, new RequestFactory(), new StreamFactory())), $http];
    }

    public function testItReadsThePersonAndTheirPrimaryVerifiedEmail(): void
    {
        // Arrange
        [$github] = $this->github([]);

        // Act
        $identity = $github->identify(self::REDIRECT, 'code-1', 'verifier-1', 'unused');

        // Assert
        $this->assertSame(['github', '583231', 'Octo@Example.com', true, 'The Octocat'], [$identity->provider, $identity->subject, $identity->email, $identity->emailVerified, $identity->name]);
        $this->assertSame('octo@example.com', $identity->verifiedEmail());
    }

    public function testItSendsTheCodeAndVerifierAndAsksTheApiWithTheTokenAndAUserAgent(): void
    {
        // Arrange
        [$github, $http] = $this->github([]);

        // Act
        $github->identify(self::REDIRECT, 'code-1', 'verifier-1', 'unused');

        // Assert
        $form = $http->lastForm('https://github.com/login/oauth/access_token');
        $this->assertSame(['code-1', 'verifier-1', 'gh-secret'], [$form['code'], $form['code_verifier'], $form['client_secret']]);
        $userRequest = array_values(array_filter($http->requests, static fn ($r): bool => $r->getUri()->getPath() === '/user'))[0];
        $this->assertSame('Bearer gho_abc', $userRequest->getHeaderLine('Authorization'));
        $this->assertSame('php-sso', $userRequest->getHeaderLine('User-Agent'));
    }

    public function testAnAddressThatIsNotPrimaryAndVerifiedDoesNotCountAsAVerifiedEmail(): void
    {
        // Arrange
        [$github] = $this->github(['https://api.github.com/user/emails' => [200, [
            ['email' => 'unverified@example.com', 'primary' => true, 'verified' => false],
            ['email' => 'second@example.com', 'primary' => false, 'verified' => true],
        ]]]);

        // Act
        $identity = $github->identify(self::REDIRECT, 'code-1', 'verifier-1', 'unused');

        // Assert
        $this->assertNull($identity->email);
        $this->assertNull($identity->verifiedEmail());
    }

    public function testItFallsBackToTheLoginNameWhenThereIsNoRealName(): void
    {
        // Arrange
        [$github] = $this->github(['https://api.github.com/user' => [200, ['id' => 1, 'login' => 'octocat', 'name' => null]]]);

        // Act
        $identity = $github->identify(self::REDIRECT, 'code-1', 'verifier-1', 'unused');

        // Assert
        $this->assertSame('octocat', $identity->name);
    }

    public function testItRefusesWhenGithubAnswersWithAnErrorInsteadOfAToken(): void
    {
        // Arrange
        [$github] = $this->github(['https://github.com/login/oauth/access_token' => [200, ['error' => 'bad_verification_code']]]);
        $this->expectException(SocialLoginException::class);

        // Act
        $github->identify(self::REDIRECT, 'wrong', 'verifier-1', 'unused');

        // Assert (the exception above)
    }

    public function testItRefusesWhenTheProfileCannotBeRead(): void
    {
        // Arrange
        [$github] = $this->github(['https://api.github.com/user' => [401, ['message' => 'Bad credentials']]]);
        $this->expectException(SocialLoginException::class);

        // Act
        $github->identify(self::REDIRECT, 'code-1', 'verifier-1', 'unused');

        // Assert (the exception above)
    }

    public function testTheAuthorizationAddressAsksForPkceAndTheEmailScope(): void
    {
        // Arrange
        [$github] = $this->github([]);

        // Act
        $url = $github->authorizationUrl(self::REDIRECT, 'state-1', 'unused', 'challenge-1');

        // Assert
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['gh-client', 'read:user user:email', 'state-1', 'challenge-1', 'S256'], [$query['client_id'], $query['scope'], $query['state'], $query['code_challenge'], $query['code_challenge_method']]);
    }
}
