<?php

declare(strict_types=1);

namespace PhpSso\Auth;

use PhpSso\Auth\Mfa\ChallengeResult;
use PhpSso\Auth\Mfa\Mfa;
use PhpSso\Auth\Mfa\MfaChallenge;
use PhpSso\Net\ClientIp;
use PhpSso\Pages;
use PhpSso\Store\Users;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The pages between a right password and being signed in: give a code from the authenticator app (or a recovery
 * code), or, when the policy requires it and nothing is set up yet, set the app up first.
 */
final class MfaLoginController
{
    public function __construct(
        private Pages $pages,
        private LoginFlow $flow,
        private Mfa $mfa,
        private MfaChallenge $challenge,
        private Users $users,
        private ClientIp $clientIp,
    ) {
    }

    public function challengeForm(string $error = '', int $status = 200): ResponseInterface
    {
        $pending = $this->flow->secondFactorPending();
        if ($pending === null || $pending['step'] !== 'challenge') {
            return $this->elsewhere($pending);
        }

        return $this->pages->render('login_mfa', $this->context() + ['error' => $error], $status);
    }

    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }
        $pending = $this->flow->secondFactorPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        if ($pending === null || $pending['step'] !== 'challenge' || $user === null) {
            return $this->elsewhere($pending);
        }

        $ip = $this->clientIp->of($request);
        $agent = $request->getHeaderLine('User-Agent');
        $result = $this->challenge->attempt($user, $pending['identifier'], (string) ($body['code'] ?? ''), $ip, $agent);
        if (!$result->accepted) {
            return $this->failed($result, fn (string $error, int $status): ResponseInterface => $this->challengeForm($error, $status));
        }

        return new RedirectResponse($this->flow->proceed($user, $pending['identifier'], $ip, $agent, $result->methods()));
    }

    public function setupForm(string $error = '', int $status = 200): ResponseInterface
    {
        $pending = $this->flow->secondFactorPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        if ($pending === null || $pending['step'] !== 'enroll' || $pending['passed'] || $user === null) {
            return $this->elsewhere($pending);
        }
        if ($this->mfa->isEnrolled($user['sub'])) {
            $this->flow->beginSecondFactor($user['sub'], $pending['identifier'], 'challenge'); // set up in another tab meanwhile

            return new RedirectResponse('/login/mfa');
        }
        $_SESSION['login']['enroll_secret'] ??= $this->mfa->newSecret();
        $secret = (string) $_SESSION['login']['enroll_secret'];

        return $this->pages->render('mfa_setup', $this->context() + [
            'error' => $error,
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => $this->mfa->otpauthUri($user, $secret),
            'qr' => $this->mfa->setupQr($user, $secret),
            'action' => '/login/mfa/setup',
            'cancel_href' => '/login',
            'required' => true,
        ], $status);
    }

    public function setup(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        if (!$this->csrfValid($body)) {
            return $this->expired();
        }
        $pending = $this->flow->secondFactorPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        $secret = $_SESSION['login']['enroll_secret'] ?? null;
        if ($pending === null || $pending['step'] !== 'enroll' || $pending['passed'] || $user === null || !is_string($secret)) {
            return $this->elsewhere($pending);
        }

        $codes = $this->mfa->enroll($user, $secret, (string) ($body['code'] ?? ''));
        if ($codes === null) {
            return $this->setupForm('That code is not right. Check the time on your phone and try the next one.', 401);
        }
        unset($_SESSION['login']['enroll_secret']);
        $this->flow->markSecondFactorPassed(['otp', 'mfa']); // they just proved they hold the app: a valid code at setup

        return $this->pages->render('mfa_recovery', $this->context() + ['codes' => $codes, 'continue_action' => '/login/mfa/continue', 'continue_href' => null]);
    }

    /** After the recovery codes were shown: finish signing in. */
    public function finish(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->csrfValid((array) $request->getParsedBody())) {
            return $this->expired();
        }
        $pending = $this->flow->secondFactorPending();
        $user = $pending === null ? null : $this->users->find($pending['sub']);
        if ($pending === null || !$pending['passed'] || $user === null) {
            return $this->elsewhere($pending);
        }

        return new RedirectResponse($this->flow->proceed($user, $pending['identifier'], $this->clientIp->of($request), $request->getHeaderLine('User-Agent')));
    }

    /**
     * @param callable(string, int): ResponseInterface $form shows the page again with an error and a status
     */
    private function failed(ChallengeResult $result, callable $form): ResponseInterface
    {
        $response = $form($result->message(), $result->lockedOut() ? 429 : 401);

        return $result->retryAfter === null ? $response : $response->withHeader('Retry-After', (string) $result->retryAfter);
    }

    /** Where someone who is on the wrong page belongs: their step if they have one, else the start. @param array{step: string}|null $pending */
    private function elsewhere(?array $pending): ResponseInterface
    {
        return new RedirectResponse($pending === null ? '/login' : ($pending['step'] === 'enroll' ? '/login/mfa/setup' : '/login/mfa'));
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
