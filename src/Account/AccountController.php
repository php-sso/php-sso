<?php

declare(strict_types=1);

namespace PhpSso\Account;

use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\MfaChallenge;
use PhpSso\Auth\Social\SocialLogin;
use PhpSso\Auth\Social\SocialLoginException;
use PhpSso\Auth\UserRules;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use PhpSso\Audit\AccountActions;
use PhpSso\Audit\Audit;
use PhpSso\Store\Sessions;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The signed-in person's own settings: profile, password and browser sessions. */
final class AccountController
{
    public function __construct(
        private Pages $pages,
        private Users $users,
        private Sessions $sessions,
        private UserRules $rules,
        private Mfa $mfa,
        private MfaChallenge $challenge,
        private ClientIp $clientIp,
        private SocialLogin $social,
        private ?Audit $audit = null,
    ) {
    }

    /** @param array $user the signed-in user's row; a CSRF failure or unknown route is reported as an error page */
    public function handle(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $path = rtrim(substr($request->getUri()->getPath(), strlen('/account')), '/') ?: '/';
        $method = $request->getMethod();

        if ($method === 'GET' && $path === '/') {
            return $this->show($user);
        }
        if ($method === 'GET' && $path === '/mfa/setup') {
            return $this->mfaSetupForm($user);
        }
        if ($method !== 'POST') {
            return $this->notFound($user);
        }

        $body = (array) $request->getParsedBody();
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($body['_csrf'] ?? ''))) {
            return $this->pages->render('error', $this->context($user) + [
                'title' => 'Page expired', 'message' => 'Go back, reload the page and try again.',
            ], 400);
        }

        $plan = $this->audit !== null ? AccountActions::plan($path) : null;
        $flashBefore = $_SESSION['flash'] ?? null;
        $response = match (true) {
            $path === '/profile' => $this->updateProfile($user, $body),
            $path === '/password' => $this->changePassword($user, $body),
            $path === '/mfa/setup' => $this->mfaSetup($user, $body),
            $path === '/mfa/recovery' => $this->mfaRecovery($user, $body, $request),
            $path === '/mfa/disable' => $this->mfaDisable($user, $body, $request),
            (bool) preg_match('#^/social/([a-z0-9_-]+)/(link|unlink)$#', $path, $sm) => $sm[2] === 'link' ? $this->socialLink($user, $sm[1]) : $this->socialUnlink($user, $sm[1]),
            $path === '/sessions/end-others' => $this->endOthers($user),
            (bool) preg_match('#^/sessions/([0-9a-f]{32})/end$#', $path, $m) => $this->endSession($user, $m[1]),
            default => $this->notFound($user),
        };
        if ($plan !== null && AccountActions::succeeded($plan, $response->getStatusCode(), ($_SESSION['flash'] ?? null) !== $flashBefore ? ($_SESSION['flash']['type'] ?? null) : null)) {
            $this->audit?->record($plan['action'], $user, $this->clientIp->of($request), $plan['type'], $plan['target'], null, $plan['detail']);
        }

        return $response;
    }

    private function show(array $user, array $errors = [], array $values = [], int $status = 200): ResponseInterface
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->pages->render('account', $this->context($user) + [
            'flash' => $flash,
            'errors' => $errors,
            'values' => $values + ['name' => $user['name'], 'email' => $user['email']],
            'groups' => $this->users->groupNames($user['sub']),
            'social' => $this->social->overview($user),
            'mfa' => ['enrolled' => $this->mfa->isEnrolled($user['sub']), 'codes_left' => $this->mfa->recoveryCodesLeft($user['sub']), 'required' => $this->mfa->requiredFor($user)],
            'sessions' => array_values(array_filter($this->sessions->active(), static fn (array $s) => $s['sub'] === $user['sub'])),
            'current_sid' => $_SESSION['sid'] ?? '',
        ], $status);
    }

    private function updateProfile(array $user, array $body): ResponseInterface
    {
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $errors = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter your name (max 100 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (($taken = $this->rules->emailError($email, $user['sub'])) !== null) {
            $errors['email'] = $taken;
        }
        if ($errors) {
            return $this->show($user, ['profile' => $errors], ['name' => $name, 'email' => $email], 422);
        }

        // A changed address is no longer verified.
        $verified = $email === $user['email'] ? (bool) $user['email_verified'] : false;
        $this->users->updateProfile($user['sub'], $name, $email, $verified);

        return $this->done('Profile saved.');
    }

    private function changePassword(array $user, array $body): ResponseInterface
    {
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        $errors = [];

        if ($user['password_hash'] === null) {
            // No local password: this person signs in some other way, and a password set here would bypass it.
            $errors['current_password'] = 'Your account has no password. Ask an administrator if you need one.';
        } elseif (!password_verify($current, $user['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }
        if (mb_strlen($new) < 8) {
            $errors['new_password'] = 'Use at least 8 characters.';
        } elseif (!hash_equals($new, (string) ($body['confirm_password'] ?? ''))) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }
        if ($errors) {
            return $this->show($user, ['password' => $errors], [], 422);
        }

        $this->users->setPassword($user['sub'], $new);
        $ended = $this->sessions->endOthers($user['sub'], (string) ($_SESSION['sid'] ?? ''));

        return $this->done($ended > 0 ? 'Password changed. Your other sessions were signed out.' : 'Password changed.');
    }

    /** Sends the person to the provider to connect an account. */
    private function socialLink(array $user, string $provider): ResponseInterface
    {
        try {
            return new RedirectResponse($this->social->begin($provider, 'link', $user['sub']));
        } catch (SocialLoginException $e) {
            return $this->failed($e->getMessage());
        }
    }

    private function socialUnlink(array $user, string $provider): ResponseInterface
    {
        $problem = $this->social->unlink($user, $provider);
        if ($problem !== null) {
            return $this->failed($problem);
        }

        return $this->done($this->social->labelFor($provider) . ' is disconnected from your account.');
    }

    private function failed(string $message): ResponseInterface
    {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $message];

        return new RedirectResponse('/account#social');
    }

    private function mfaSetupForm(array $user, string $error = '', int $status = 200): ResponseInterface
    {
        if ($this->mfa->isEnrolled($user['sub'])) {
            return new RedirectResponse('/account#mfa');
        }
        $_SESSION['mfa_setup'] ??= $this->mfa->newSecret();
        $secret = (string) $_SESSION['mfa_setup'];

        return $this->pages->render('mfa_setup', $this->context($user) + [
            'error' => $error,
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => $this->mfa->otpauthUri($user, $secret),
            'qr' => $this->mfa->setupQr($user, $secret),
            'action' => '/account/mfa/setup',
            'cancel_href' => '/account#mfa',
            'required' => $this->mfa->requiredFor($user),
        ], $status);
    }

    private function mfaSetup(array $user, array $body): ResponseInterface
    {
        $secret = $_SESSION['mfa_setup'] ?? null;
        if (!is_string($secret) || $this->mfa->isEnrolled($user['sub'])) {
            return new RedirectResponse('/account/mfa/setup');
        }
        $codes = $this->mfa->enroll($user, $secret, (string) ($body['code'] ?? ''));
        if ($codes === null) {
            return $this->mfaSetupForm($user, 'That code is not right. Check the time on your phone and try the next one.', 401);
        }
        unset($_SESSION['mfa_setup']);

        return $this->recoveryCodes($user, $codes);
    }

    /** New recovery codes, after a code from the app confirms it is really the person. */
    private function mfaRecovery(array $user, array $body, ServerRequestInterface $request): ResponseInterface
    {
        if (($failure = $this->confirmCode($user, $body, $request)) !== null) {
            return $failure;
        }

        return $this->recoveryCodes($user, $this->mfa->regenerateRecoveryCodes($user['sub']));
    }

    private function mfaDisable(array $user, array $body, ServerRequestInterface $request): ResponseInterface
    {
        if ($this->mfa->requiredFor($user)) {
            return $this->show($user, ['mfa' => ['code' => 'Your organisation requires two-step verification.']], [], 403);
        }
        if (($failure = $this->confirmCode($user, $body, $request)) !== null) {
            return $failure;
        }
        $this->mfa->disable($user['sub']);

        return $this->done('Two-step verification is off.');
    }

    /** @return ResponseInterface|null the account page with the reason, when the code does not confirm the person */
    private function confirmCode(array $user, array $body, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->mfa->isEnrolled($user['sub'])) {
            return new RedirectResponse('/account#mfa');
        }
        $result = $this->challenge->attempt($user, $user['username'], (string) ($body['code'] ?? ''), $this->clientIp->of($request), $request->getHeaderLine('User-Agent'));
        if ($result->accepted) {
            return null;
        }
        $response = $this->show($user, ['mfa' => ['code' => $result->message()]], [], $result->lockedOut() ? 429 : 401);

        return $result->retryAfter === null ? $response : $response->withHeader('Retry-After', (string) $result->retryAfter);
    }

    /** @param string[] $codes */
    private function recoveryCodes(array $user, array $codes): ResponseInterface
    {
        return $this->pages->render('mfa_recovery', $this->context($user) + ['codes' => $codes, 'continue_action' => null, 'continue_href' => '/account#mfa']);
    }

    private function endSession(array $user, string $id): ResponseInterface
    {
        if ($id === ($_SESSION['sid'] ?? '')) {
            return new RedirectResponse('/logout'); // ending your own session is signing out
        }
        $this->sessions->endForUser($id, $user['sub']);

        return $this->done('Session signed out.');
    }

    private function endOthers(array $user): ResponseInterface
    {
        $n = $this->sessions->endOthers($user['sub'], (string) ($_SESSION['sid'] ?? ''));

        return $this->done($n > 0 ? 'Signed out of your other sessions.' : 'You have no other sessions.');
    }

    private function done(string $message): ResponseInterface
    {
        $_SESSION['flash'] = ['type' => 'success', 'message' => $message];

        return new RedirectResponse('/account');
    }

    private function notFound(array $user): ResponseInterface
    {
        return $this->pages->render('error', $this->context($user) + ['title' => 'Page not found', 'message' => 'There is nothing at this address.'], 404);
    }

    /** Variables every page receives. */
    private function context(array $user): array
    {
        return [
            'csrf' => $_SESSION['csrf'] ?? '',
            'user' => ['name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'is_admin' => (bool) $user['is_admin']],
        ];
    }
}
