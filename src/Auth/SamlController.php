<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Social\AccountMatcher;
use PhpSso\Auth\Social\ExternalIdentity;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\Auth\AccountPolicy;
use PhpSso\Net\ClientIp;
use PhpSso\Saml\ServiceProvider\SignIn;
use PhpSso\Saml\ServiceProvider\SignInException;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in through a company's identity provider. `/login/saml/{id}` sends the browser there, `/saml/{id}/acs` is where
 * the provider's signed answer arrives (a POST from another site, so no session cookie and no CSRF token: the answer is
 * tied to our request and to the browser by the SAML library), and `/saml/{id}/metadata` describes us to the company's
 * administrator. A successful answer is a first factor, like a password.
 */
final class SamlController
{
    private const COOKIE = 'sso_saml';

    public function __construct(
        private SignIn $saml,
        private SamlConnections $connections,
        private AccountMatcher $matcher,
        private AccountPolicy $policy,
        private LoginFlow $flow,
        private Mfa $mfa,
        private ClientIp $clientIp,
    ) {
    }

    public function start(int $id): ResponseInterface
    {
        $returnTo = $_SESSION['return_to'] ?? null;
        try {
            $started = $this->saml->begin($id, is_string($returnTo) ? $returnTo : null);
        } catch (SignInException $e) {
            return $this->failed($e);
        }

        return (new RedirectResponse($started->url, 303))->withAddedHeader('Set-Cookie', $this->cookie($started->binding, 600));
    }

    public function acs(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        try {
            $result = $this->saml->finish($id, (string) ($body['SAMLResponse'] ?? ''), is_string($cookie) ? $cookie : null);
            $connection = $this->connections->find($id) ?? throw new SignInException('Signing in through your organisation is not available.', "No connection {$id}.");
            $signedIn = $result->identity;
            $identity = new ExternalIdentity('saml-' . $id, $signedIn->nameId, $signedIn->email, $signedIn->emailVerified, $signedIn->name);
            $email = $identity->verifiedEmail();
            $user = $this->matcher->match(
                $identity,
                (string) $connection['name'],
                (bool) $connection['link_existing'],
                (bool) $connection['create_users'],
                $email !== null && $this->policy->domainAllowed($email),
            );
        } catch (SignInException|SocialLoginException $e) {
            return $this->failed($e)->withAddedHeader('Set-Cookie', $this->cookie('', 0));
        }

        // The browser arrives from another site without its session cookie, so this is usually a fresh session: where it was heading comes from our record of the request.
        if ($result->returnTo !== null) {
            $_SESSION['return_to'] = $result->returnTo;
        }
        $identifier = mb_substr($identity->provider . ':' . ($identity->email ?? $identity->subject), 0, 100);
        if (Users::isLocked($user)) {
            $_SESSION['login_error'] = 'This account is locked. Ask an administrator to unlock it.';

            return (new RedirectResponse('/login', 303))->withAddedHeader('Set-Cookie', $this->cookie('', 0));
        }
        $step = $this->mfa->stepFor($user);
        $url = $this->flow->primaryAuthenticated($user, $identifier, $step, $this->clientIp->of($request), $request->getHeaderLine('User-Agent'), ['fed']);

        return (new RedirectResponse($url, 303))->withAddedHeader('Set-Cookie', $this->cookie('', 0));
    }

    /** What the company's administrator needs to register us. */
    public function metadata(int $id): ResponseInterface
    {
        if ($this->connections->find($id) === null) {
            return new TextResponse('Not found', 404);
        }

        return new TextResponse($this->saml->metadataXml($id), 200, ['Content-Type' => 'application/samlmetadata+xml; charset=utf-8']);
    }

    private function failed(SignInException|SocialLoginException $e): ResponseInterface
    {
        if ($e->detail() !== '') {
            error_log('SSO SAML sign-in: ' . $e->detail());
        }
        $_SESSION['login_error'] = $e->getMessage();

        return new RedirectResponse('/login', 303);
    }

    /**
     * The cookie that ties the answer to the browser that started the request. It has to survive a POST from another
     * site, which only SameSite=None allows, and that needs https; over plain http (development) it is best effort.
     */
    private function cookie(string $value, int $maxAge): string
    {
        return self::COOKIE . '=' . $value . '; Path=/saml; Max-Age=' . $maxAge . '; HttpOnly; '
            . ($this->saml->secure() ? 'Secure; SameSite=None' : 'SameSite=Lax');
    }
}
