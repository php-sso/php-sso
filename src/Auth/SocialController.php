<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\Net\ClientIp;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two addresses of a round trip to a provider: `/login/social/{provider}` sends the browser there, and
 * `/login/social/{provider}/callback` is where the provider sends it back. A successful sign-in is a first factor, like
 * a password: a person with two-step verification is still asked for a code.
 */
final class SocialController
{
    public function __construct(
        private SocialLogin $social,
        private LoginFlow $flow,
        private Mfa $mfa,
        private ClientIp $clientIp,
    ) {
    }

    public function start(string $provider): ResponseInterface
    {
        try {
            return new RedirectResponse($this->social->begin($provider));
        } catch (SocialLoginException $e) {
            return $this->failed($e, 'login');
        }
    }

    public function callback(ServerRequestInterface $request, string $provider): ResponseInterface
    {
        $intent = $this->social->pendingIntent();
        try {
            $result = $this->social->finish($provider, $request->getQueryParams());
        } catch (SocialLoginException $e) {
            return $this->failed($e, $intent);
        }

        if ($result['intent'] === 'link') {
            $_SESSION['flash'] = ['type' => 'success', 'message' => $this->social->labelFor($provider) . ' is connected to your account.'];

            return new RedirectResponse('/account#social');
        }

        $identity = $result['identity'];
        $identifier = mb_substr($identity->provider . ':' . ($identity->email ?? $identity->subject), 0, 100);
        if (Users::isLocked($result['user'])) {
            $_SESSION['login_error'] = 'This account is locked. Ask an administrator to unlock it.';

            return new RedirectResponse('/login');
        }
        $step = $this->mfa->stepFor($result['user']);
        $url = $this->flow->primaryAuthenticated($result['user'], $identifier, $step, $this->clientIp->of($request), $request->getHeaderLine('User-Agent'), ['fed']);

        return new RedirectResponse($url, $step === 'none' ? 302 : 303);
    }

    private function failed(SocialLoginException $e, string $intent): ResponseInterface
    {
        if ($e->detail() !== '') {
            error_log('SSO social sign-in: ' . $e->detail());
        }
        if ($intent === 'link') {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];

            return new RedirectResponse('/account#social');
        }
        $_SESSION['login_error'] = $e->getMessage();

        return new RedirectResponse('/login');
    }
}
