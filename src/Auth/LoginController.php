<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Identifier\IdentifierResolver;
use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Social\SocialProviders;
use PhpSso\IpPolicy;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use PhpSso\Store\LoginAttempts;
use PhpSso\Store\SamlConnections;
use PhpSso\Store\Users;
use Closure;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use PhpSso\Store\UserState;

/**
 * The sign-in pages. Signing in takes two steps: say who you are (/login), then prove it (/login/password).
 * The first step answers the same for a known and an unknown identifier, so it does not reveal who has an account.
 */
final class LoginController
{
    private const DUMMY_HASH = '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvali';

    public function __construct(
        private Pages $pages,
        private LoginFlow $flow,
        private IdentifierResolver $identifiers,
        private Users $users,
        private LoginAttempts $attempts,
        private ClientIp $clientIp,
        private LoginThrottle $throttle,
        private Mfa $mfa,
        private SocialProviders $social,
        private SamlConnections $saml,
        private IpPolicy $ipPolicy,
    ) {
    }

    /** Step one: ask who is signing in. */
    public function identifyForm(string $error = '', string $typed = '', int $status = 200): ResponseInterface
    {
        if ($error === '' && is_string($_SESSION['login_error'] ?? null)) {
            $error = $_SESSION['login_error']; // left by a social sign-in that failed
        }
        unset($_SESSION['login_error']);
        $notice = is_string($_SESSION['login_notice'] ?? null) ? $_SESSION['login_notice'] : ''; // left by a password reset that worked
        unset($_SESSION['login_notice']);

        return $this->pages->render('login', $this->context() + [
            'error' => $error,
            'notice' => $notice,
            'social' => $this->social->buttons(),
            'username' => $typed,
            'identifier' => ['label' => $this->identifiers->label(), 'type' => $this->identifiers->inputType()],
        ], $status);
    }

    /**
     * Step one, submitted. The person is remembered for the next step whether or not they exist.
     * A form that also sends a password (a theme written for the old one-step page) is handled as one step.
     */
    public function identify(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }

        $typed = (string) ($body['username'] ?? '');
        if (trim($typed) === '') {
            return $this->identifyForm('Enter your ' . mb_strtolower($this->identifiers->label()) . '.', '', 400);
        }
        $identifier = $this->identifiers->normalize(mb_substr($typed, 0, 255));

        // An address at a domain a company claims: that company's identity provider signs the person in, no password page.
        $domain = $this->identifiers->domain($identifier);
        $company = $domain === null ? null : $this->saml->findByDomain($domain);
        if ($company !== null) {
            return new RedirectResponse('/login/saml/' . $company['id'], 303);
        }
        $user = $this->identifiers->find($identifier);

        if (array_key_exists('password', $body)) {
            return $this->attempt($identifier, $user, (string) $body['password'], $request, fn (string $error, int $status): ResponseInterface => $this->identifyForm($error, $identifier, $status));
        }

        $this->flow->begin($identifier, $user['sub'] ?? null);

        return new RedirectResponse('/login/password', 303);
    }

    /** Step two: ask for the password. Back to step one when nobody is pending. */
    public function passwordForm(string $error = '', int $status = 200): ResponseInterface
    {
        $pending = $this->flow->pending();
        if ($pending === null) {
            return new RedirectResponse('/login');
        }

        return $this->pages->render('login_password', $this->context() + ['error' => $error, 'username' => $pending['identifier']], $status);
    }

    /** Step two, submitted. */
    public function password(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }
        $pending = $this->flow->pending();
        if ($pending === null) {
            return new RedirectResponse('/login');
        }
        $user = $pending['sub'] === null ? null : $this->users->find($pending['sub']);

        return $this->attempt($pending['identifier'], $user, (string) ($body['password'] ?? ''), $request, fn (string $error, int $status): ResponseInterface => $this->passwordForm($error, $status));
    }

    /**
     * Checks the password and signs the person in, or answers through $form. Too many recent failures for this
     * identifier, account or address stop the attempt before any password is checked.
     *
     * @param array<string, mixed>|null $user
     * @param Closure(string, int): ResponseInterface $form shows the page again with an error and a status
     */
    private function attempt(string $identifier, ?array $user, string $password, ServerRequestInterface $request, Closure $form): ResponseInterface
    {
        $ip = $this->clientIp->of($request);
        $agent = $request->getHeaderLine('User-Agent');

        $wait = $this->throttle->lockedFor($identifier, $user['sub'] ?? null, $ip);
        if ($wait !== null) {
            $this->throttle->recordLocked($identifier, $user['sub'] ?? null, $ip, $agent);

            return $form('Too many failed attempts. Try again in ' . ($wait < 90 ? 'a minute' : (int) ceil($wait / 60) . ' minutes') . '.', 429)
                ->withHeader('Retry-After', (string) $wait);
        }

        // Verify against a dummy hash for unknown users so timing doesn't reveal which identifiers exist.
        // A person whose company signs them in (their address is at a claimed domain) has no use for a password here: it would
        // bypass the company's two-step verification and its offboarding. The answer is the same as for a wrong password.
        $passwordRight = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$passwordRight || $user === null || $this->saml->managesAddress((string) $user['email'])) {
            $this->attempts->record($identifier, $user['sub'] ?? null, false, $ip, $agent);

            return $form('Invalid credentials', 401);
        }

        // Said only after the right password, so it does not reveal which usernames exist.
        if (UserState::isLocked($user)) {
            $this->attempts->record($identifier, $user['sub'], false, $ip, $agent);

            return $form('This account is locked. Ask an administrator to unlock it.', 403);
        }

        // Also said only after the right password. The person's own IP rules come on top of the general ones, which Sso already applied.
        if (!$this->ipPolicy->allowsUser($ip, $user['sub'])) {
            $this->attempts->record($identifier, $user['sub'], false, $ip, $agent, true);

            return $form('Signing in is not allowed from your network address.', 403);
        }

        // The password is right. A person with a second factor (or whom the policy makes set one up) is not signed in yet.
        $step = $this->mfa->stepFor($user);

        return new RedirectResponse($this->flow->primaryAuthenticated($user, $identifier, $step, $ip, $agent), $step === 'none' ? 302 : 303);
    }

    /** @param array<mixed> $body */
    private function csrfValid(array $body): bool
    {
        return hash_equals($_SESSION['csrf'] ?? '', (string) ($body['_csrf'] ?? ''));
    }

    private function expired(): ResponseInterface
    {
        return $this->pages->render('error', $this->context() + ['title' => 'Page expired', 'message' => 'Go back, reload the sign-in page and try again.'], 400);
    }

    /** @return array{csrf: string, user: null} */
    private function context(): array
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return ['csrf' => $_SESSION['csrf'], 'user' => null];
    }
}
